<?php

namespace MediaWiki\Tests\Rest\Handler;

use HashConfig;
use Language;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\Handler\SearchHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MockSearchResultSet;
use PHPUnit\Framework\MockObject\MockObject;
use SearchEngine;
use SearchEngineFactory;
use SearchResult;
use SearchResultSet;
use SearchSuggestion;
use SearchSuggestionSet;
use Status;
use User;
use Wikimedia\Message\MessageValue;

/**
 * @covers \MediaWiki\Rest\Handler\SearchHandler
 */
class SearchHandlerTest extends \MediaWikiUnitTestCase {

	use HandlerTestTrait;

	/**
	 * @var SearchEngine|MockObject|null
	 */
	private $searchEngine = null;

	/**
	 * @param $query
	 * @param SearchResultSet|Status $titleResult
	 * @param SearchResultSet|Status $textResult
	 * @param SearchSuggestionSet|null $completionResult
	 *
	 * @return SearchHandler
	 */
	private function newHandler(
		$query,
		$titleResult,
		$textResult,
		$completionResult = null
	) {
		$config = new HashConfig( [
			'SearchType' => 'test',
			'SearchTypeAlternatives' => [],
			'NamespacesToBeSearchedDefault' => [ NS_MAIN => true ],
		] );

		/** @var Language|MockObject $language */
		$language = $this->createNoOpMock( Language::class );
		$searchEngineConfig = new \SearchEngineConfig( $config, $language );

		/** @var PermissionManager|MockObject $permissionManager */
		$permissionManager = $this->createNoOpMock(
			PermissionManager::class, [ 'quickUserCan' ]
		);
		$permissionManager->method( 'quickUserCan' )
			->willReturnCallback( function ( $action, User $user, LinkTarget $page ) {
				return !preg_match( '/Forbidden/', $page->getText() );
			} );

		/** @var SearchEngine|MockObject $searchEngine */
		$this->searchEngine = $this->createMock( SearchEngine::class );
		$this->searchEngine->method( 'searchTitle' )
			->with( $query )
			->willReturn( $titleResult );
		$this->searchEngine->method( 'searchText' )
			->with( $query )
			->willReturn( $textResult );

		if ( $completionResult ) {
			$this->searchEngine->method( 'completionSearchWithVariants' )
				->with( $query )
				->willReturn( $completionResult );
		}

		/** @var SearchEngineFactory|MockObject $searchEngineFactory */
		$searchEngineFactory = $this->createNoOpMock( SearchEngineFactory::class, [ 'create' ] );
		$searchEngineFactory->method( 'create' )
			->willReturn( $this->searchEngine );

		return new SearchHandler(
			$permissionManager,
			$searchEngineFactory,
			$searchEngineConfig
		);
	}

	/**
	 * @param string $pageName
	 * @param string $textSnippet
	 * @param bool $broken
	 * @param bool $missing
	 *
	 * @return SearchResult
	 */
	private function makeMockSearchResult(
		$pageName,
		$textSnippet = 'Lorem Ipsum',
		$broken = false,
		$missing = false
	) {
		$title = $this->makeMockTitle( $pageName );

		/** @var SearchResult|MockObject $result */
		$result = $this->createNoOpMock( SearchResult::class, [
			'getTitle', 'isBrokenTitle', 'isMissingRevision', 'getTextSnippet'
		] );
		$result->method( 'getTitle' )->willReturn( $title );
		$result->method( 'getTextSnippet' )->willReturn( $textSnippet );
		$result->method( 'isBrokenTitle' )->willReturn( $broken );
		$result->method( 'isMissingRevision' )->willReturn( $missing );

		return $result;
	}

	/**
	 * @param string $pageName
	 *
	 * @return SearchSuggestion
	 */
	private function makeMockSearchSuggestion( $pageName ) {
		$title = $this->makeMockTitle( $pageName );

		/** @var SearchSuggestion|MockObject $suggestion */
		$suggestion = $this->createNoOpMock(
			SearchSuggestion::class,
			[ 'getSuggestedTitle', 'getSuggestedTitleID', 'getText' ]
		);
		$suggestion->method( 'getSuggestedTitle' )->willReturn( $title );
		$suggestion->method( 'getSuggestedTitleID' )->willReturn( $title->getArticleID() );
		$suggestion->method( 'getText' )->willReturn( $title->getPrefixedText() );

		return $suggestion;
	}

	public function testExecuteFulltextSearch() {
		$titleResults = new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Foo', 'one' ),
			$this->makeMockSearchResult( 'Forbidden Foo', 'two' ), // forbidden
			$this->makeMockSearchResult( 'FooBar', 'three' ),
			$this->makeMockSearchResult( 'Foo Moo', 'four', true, false ), // missing
		] );
		$textResults = new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Quux', 'one' ),
			$this->makeMockSearchResult( 'Forbidden Quux', 'two' ), // forbidden
			$this->makeMockSearchResult( 'Xyzzy', 'three' ),
			$this->makeMockSearchResult( 'Yookoo', 'four', false, true ), // broken
		] );

		$query = 'foo';
		$request = new RequestData( [ 'queryParams' => [ 'q' => $query ] ] );

		$handler = $this->newHandler( $query, $titleResults, $textResults );
		$config = [ 'mode' => SearchHandler::FULLTEXT_MODE ];
		$data = $this->executeHandlerAndGetBodyData( $handler, $request, $config );

		$this->assertArrayHasKey( 'pages', $data );
		$this->assertCount( 4, $data['pages'] );
		$this->assertSame( 'Foo', $data['pages'][0]['title'] );
		$this->assertSame( 'one', $data['pages'][0]['excerpt'] );
		$this->assertSame( 'FooBar', $data['pages'][1]['title'] );
		$this->assertSame( 'three', $data['pages'][1]['excerpt'] );
		$this->assertSame( 'Quux', $data['pages'][2]['title'] );
		$this->assertSame( 'one', $data['pages'][2]['excerpt'] );
		$this->assertSame( 'Xyzzy', $data['pages'][3]['title'] );
		$this->assertSame( 'three', $data['pages'][3]['excerpt'] );
	}

	public function testExecuteCompletionSearch() {
		$titleResults = new MockSearchResultSet( [] );
		$textResults = new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Quux', 'one' ),
		] );
		$completionResults = new SearchSuggestionSet( [
			$this->makeMockSearchSuggestion( 'Frob' ),
			$this->makeMockSearchSuggestion( 'Frobnitz' ),
		] );

		$query = 'foo';
		$request = new RequestData( [ 'queryParams' => [ 'q' => $query ] ] );

		$handler = $this->newHandler( $query, $titleResults, $textResults, $completionResults );
		$config = [ 'mode' => SearchHandler::COMPLETION_MODE ];
		$data = $this->executeHandlerAndGetBodyData( $handler, $request, $config );

		$this->assertArrayHasKey( 'pages', $data );
		$this->assertCount( 2, $data['pages'] );
		$this->assertSame( 'Frob', $data['pages'][0]['title'] );
		$this->assertSame( 'Frob', $data['pages'][0]['excerpt'] );
		$this->assertSame( 'Frobnitz', $data['pages'][1]['title'] );
		$this->assertSame( 'Frobnitz', $data['pages'][1]['excerpt'] );
	}

	public function testExecute_limit() {
		$titleResults = new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Foo' ),
			$this->makeMockSearchResult( 'FooBar' ),
		] );
		$textResults = new MockSearchResultSet( [] );

		$query = 'foo';
		$request = new RequestData( [ 'queryParams' => [ 'q' => $query, 'limit' => 7 ] ] );

		$handler = $this->newHandler( $query, $titleResults, $textResults );

		// Limits are enforced by the SearchEngine, which we mock.
		// So we have to do assertions on the mock, not on the result data.
		$this->searchEngine
			->expects( $this->atLeastOnce() )
			->method( 'setLimitOffset' )
			->with( 7, 0 );

		$this->executeHandler( $handler, $request );
	}

	public function testExecute_limit_default() {
		$titleResults = new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Foo' ),
			$this->makeMockSearchResult( 'FooBar' ),
		] );
		$textResults = new MockSearchResultSet( [] );

		$query = 'foo';
		$request = new RequestData( [ 'queryParams' => [ 'q' => $query ] ] );

		$handler = $this->newHandler( $query, $titleResults, $textResults );

		// Limits are enforced by the SearchEngine, which we mock.
		// So we have to do assertions on the mock, not on the result data.
		$this->searchEngine
			->expects( $this->atLeastOnce() )
			->method( 'setLimitOffset' )
			->with( 50, 0 );

		$this->executeHandler( $handler, $request );
	}

	public function provideExecute_limit_error() {
		yield [ 0, 'paramvalidator-outofrange-minmax' ];
		yield [ 123, 'paramvalidator-outofrange-minmax' ];
		yield [ 'xyz', 'paramvalidator-badinteger' ];
	}

	/**
	 * @dataProvider provideExecute_limit_error
	 * @param int $requestedLimit
	 * @param string $error
	 */
	public function testExecute_limit_error( $requestedLimit, $error ) {
		$titleResults = new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Foo' ),
			$this->makeMockSearchResult( 'FooBar' ),
		] );
		$textResults = new MockSearchResultSet( [] );

		$query = 'foo';
		$request =
			new RequestData( [ 'queryParams' => [ 'q' => $query, 'limit' => $requestedLimit ] ] );

		$handler = $this->newHandler( $query, $titleResults, $textResults );

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( $error ), 400 )
		);

		$this->executeHandler( $handler, $request );
	}

	public function testExecute_status() {
		$titleResults = Status::newGood( new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Foo', 'one' ),
			$this->makeMockSearchResult( 'FooBar', 'three' ),
		] ) );
		$textResults = Status::newGood( new MockSearchResultSet( [
			$this->makeMockSearchResult( 'Quux', 'one' ),
			$this->makeMockSearchResult( 'Xyzzy', 'three' ),
		] ) );

		$query = 'foo';
		$request = new RequestData( [ 'queryParams' => [ 'q' => $query ] ] );

		$handler = $this->newHandler( $query, $titleResults, $textResults );
		$data = $this->executeHandlerAndGetBodyData( $handler, $request );

		$this->assertArrayHasKey( 'pages', $data );
		$this->assertCount( 4, $data['pages'] );
	}

	public function testExecute_missingparam() {
		$titleResults = Status::newFatal( 'testing' );
		$textResults = new MockSearchResultSet( [] );

		$query = '';
		$request = new RequestData( [ 'queryParams' => [ 'q' => $query ] ] );

		$this->expectExceptionObject(
			new LocalizedHttpException(
				new MessageValue( "paramvalidator-missingparam", [ 'q' ] ),
				400
			)
		);

		$handler = $this->newHandler( $query, $titleResults, $textResults );
		$this->executeHandler( $handler, $request );
	}

	public function testExecute_error() {
		$titleResults = Status::newFatal( 'testing' );
		$textResults = new MockSearchResultSet( [] );

		$query = 'foo';
		$request = new RequestData( [ 'queryParams' => [ 'q' => $query ] ] );

		$this->expectExceptionObject(
			new LocalizedHttpException( new MessageValue( "rest-search-error", [ 'testing' ] ) )
		);

		$handler = $this->newHandler( $query, $titleResults, $textResults );
		$this->executeHandler( $handler, $request );
	}

	public function testNeedsWriteWriteAccess() {
		$titleResults = new MockSearchResultSet( [] );
		$textResults = new MockSearchResultSet( [] );

		$handler = $this->newHandler( '', $titleResults, $textResults );
		$this->assertTrue( $handler->needsReadAccess() );
		$this->assertFalse( $handler->needsWriteAccess() );
	}

}
