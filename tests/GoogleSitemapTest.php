<?php

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Versioning\Versioned;

/**
 * TODO: Migrate to new instance level interface instead of using static methods for retrieval of site maps and items (i.e. ->getSitemaps() instead of ::get_sitemaps()).
 *
 * @package googlesitemaps
 * @subpackage tests
 */
class GoogleSitemapTest extends FunctionalTest
{

    public static $fixture_file = 'googlesitemaps/tests/GoogleSitemapTest.yml';

    protected $extraDataObjects = array(
        'GoogleSitemapTest_DataObject',
        'GoogleSitemapTest_OtherDataObject',
        'GoogleSitemapTest_UnviewableDataObject',
        'SilverStripe\GoogleSitemaps\Test_DataObject',
    );

    public function setUp()
    {
        parent::setUp();

        Config::inst()->update('GoogleSitemap', 'use_show_in_search', true);
        GoogleSitemap::clear_registered_dataobjects();
        GoogleSitemap::clear_registered_routes();
    }

    public function tearDown()
    {
        parent::tearDown();

        GoogleSitemap::clear_registered_dataobjects();
        GoogleSitemap::clear_registered_routes();
    }

    public function testIndexFileWithCustomRoute()
    {
        GoogleSitemap::register_route('/test/');

        $response = $this->get('sitemap.xml');
        $body = $response->getBody();

        $expected = "<loc>". Director::absoluteURL("sitemap.xml/sitemap/GoogleSitemapRoute/1") ."</loc>";
        $this->assertEquals(1, substr_count($body, $expected), 'A link to the custom routes exists');
    }


    public function testGetItems()
    {
        GoogleSitemap::register_dataobject("GoogleSitemapTest_DataObject", '');

        $items = GoogleSitemap::get_items('GoogleSitemapTest_DataObject', 1);
        $this->assertEquals(2, $items->count());

        $this->assertDOSEquals(array(
            array("Priority" => "0.2"),
            array("Priority" => "0.4")
        ), $items);

        GoogleSitemap::register_dataobject("GoogleSitemapTest_OtherDataObject");
        $this->assertEquals(1, GoogleSitemap::get_items('GoogleSitemapTest_OtherDataObject', 1)->count());

        GoogleSitemap::register_dataobject("GoogleSitemapTest_UnviewableDataObject");
        $this->assertEquals(0, GoogleSitemap::get_items('GoogleSitemapTest_UnviewableDataObject', 1)->count());
    }

    public function testGetItemsWithCustomRoutes()
    {
        GoogleSitemap::register_routes(array(
            '/test-route/',
            '/someother-route/',
            '/fake-sitemap-route/'
        ));

        $items = GoogleSitemap::get_items('GoogleSitemapRoute', 1);
        $this->assertEquals(3, $items->count());
    }

    public function testAccessingSitemapRootXMLFile()
    {
        GoogleSitemap::register_dataobject("GoogleSitemapTest_DataObject");
        GoogleSitemap::register_dataobject("GoogleSitemapTest_OtherDataObject");

        $response = $this->get('sitemap.xml');
        $body = $response->getBody();

        // the sitemap should contain <loc> to both those files and not the other
        // dataobject as it hasn't been registered
        $expected = "<loc>". Director::absoluteURL("sitemap.xml/sitemap/GoogleSitemapTest_DataObject/1") ."</loc>";
        $this->assertEquals(1, substr_count($body, $expected), 'A link to GoogleSitemapTest_DataObject exists');

        $expected = "<loc>". Director::absoluteURL("sitemap.xml/sitemap/GoogleSitemapTest_OtherDataObject/1") ."</loc>";
        $this->assertEquals(1, substr_count($body, $expected), 'A link to GoogleSitemapTest_OtherDataObject exists');

        $expected = "<loc>". Director::absoluteURL("sitemap.xml/sitemap/GoogleSitemapTest_UnviewableDataObject/2") ."</loc>";
        $this->assertEquals(0, substr_count($body, $expected), 'A link to a GoogleSitemapTest_UnviewableDataObject does not exist');
    }

    public function testLastModifiedDateOnRootXML()
    {
        Config::inst()->update('GoogleSitemap', 'enabled', true);

        $page = $this->objFromFixture(SiteTree::class, 'Page1');
        $page->publish('Stage', 'Live');
        $page->flushCache();

        $page2 = $this->objFromFixture(SiteTree::class, 'Page2');
        $page2->publish('Stage', 'Live');
        $page2->flushCache();

        DB::query("UPDATE \"SiteTree_Live\" SET \"LastEdited\"='2014-03-14 00:00:00' WHERE \"ID\"='".$page->ID."'");
        DB::query("UPDATE \"SiteTree_Live\" SET \"LastEdited\"='2014-01-01 00:00:00' WHERE \"ID\"='".$page2->ID."'");

        $response = $this->get('sitemap.xml');
        $body = $response->getBody();

        $expected = '<lastmod>2014-03-14</lastmod>';

        $this->assertEquals(1, substr_count($body, $expected), 'The last mod date should use most recent LastEdited date');
    }

    public function testIndexFilePaginatedSitemapFiles()
    {
        $original = Config::inst()->get('GoogleSitemap', 'objects_per_sitemap');
        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', 1);
        GoogleSitemap::register_dataobject("GoogleSitemapTest_DataObject");

        $response = $this->get('sitemap.xml');
        $body = $response->getBody();
        $expected = "<loc>". Director::absoluteURL("sitemap.xml/sitemap/GoogleSitemapTest_DataObject/1") ."</loc>";
        $this->assertEquals(1, substr_count($body, $expected), 'A link to the first page of GoogleSitemapTest_DataObject exists');

        $expected = "<loc>". Director::absoluteURL("sitemap.xml/sitemap/GoogleSitemapTest_DataObject/2") ."</loc>";
        $this->assertEquals(1, substr_count($body, $expected), 'A link to the second page GoogleSitemapTest_DataObject exists');

        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', $original);
    }

    public function testRegisterRoutesIncludesAllRoutes()
    {
        GoogleSitemap::register_route('/test/');
        GoogleSitemap::register_routes(array(
            '/test/', // duplication should be replaced
            '/unittests/',
            '/anotherlink/'
        ), 'weekly');

        $response = $this->get('sitemap.xml/sitemap/GoogleSitemapRoute/1');
        $body = $response->getBody();

        $this->assertEquals(200, $response->getStatusCode(), 'successful loaded nested sitemap');
        $this->assertEquals(3, substr_count($body, "<loc>"));
    }

    public function testAccessingNestedSiteMap()
    {
        $original = Config::inst()->get('GoogleSitemap', 'objects_per_sitemap');
        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', 1);
        GoogleSitemap::register_dataobject("GoogleSitemapTest_DataObject");

        $response = $this->get('sitemap.xml/sitemap/GoogleSitemapTest_DataObject/1');
        $body = $response->getBody();

        $this->assertEquals(200, $response->getStatusCode(), 'successful loaded nested sitemap');

        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', $original);
    }

    public function testAccessingNestedSiteMapCaseInsensitive()
    {
        $original = Config::inst()->get('GoogleSitemap', 'objects_per_sitemap');
        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', 1);
        GoogleSitemap::register_dataobject("GoogleSitemapTest_DataObject");

        $response = $this->get('sitemap.xml/sitemap/googlesitemaptest_dataobject/1');
        $body = $response->getBody();

        $this->assertEquals(200, $response->getStatusCode(), 'successful loaded nested sitemap');

        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', $original);
    }

    public function testAccessingNestedNamespacedSiteMap()
    {
        $original = Config::inst()->get('GoogleSitemap', 'objects_per_sitemap');
        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', 1);
        GoogleSitemap::register_dataobject("SilverStripe\\GoogleSitemaps\\Test_DataObject");

        $response = $this->get('sitemap.xml/sitemap/SilverStripe-GoogleSitemaps-Test_DataObject/1');
        $body = $response->getBody();

        $this->assertEquals(200, $response->getStatusCode(), 'successful loaded nested sitemap');

        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', $original);
    }

    public function testAccessingNestedNamespacedSiteMapCaseInsensitive()
    {
        $original = Config::inst()->get('GoogleSitemap', 'objects_per_sitemap');
        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', 1);
        GoogleSitemap::register_dataobject("SilverStripe\\GoogleSitemaps\\Test_DataObject");

        $response = $this->get('sitemap.xml/sitemap/silverstripe-googlesitemaps-test_dataobject/1');
        $body = $response->getBody();

        $this->assertEquals(200, $response->getStatusCode(), 'successful loaded nested sitemap');

        Config::inst()->update('GoogleSitemap', 'objects_per_sitemap', $original);
    }

    public function testGetItemsWithPages()
    {
        $page = $this->objFromFixture(SiteTree::class, 'Page1');
        $page->publish('Stage', 'Live');
        $page->flushCache();

        $page2 = $this->objFromFixture(SiteTree::class, 'Page2');
        $page2->publish('Stage', 'Live');
        $page2->flushCache();

        $this->assertDOSContains(array(
            array('Title' => 'Testpage1'),
            array('Title' => 'Testpage2')
        ), GoogleSitemap::get_items(SiteTree::class), "There should be 2 pages in the sitemap after publishing");

        // check if we make a page readonly that it is hidden
        $page2->CanViewType = 'LoggedInUsers';
        $page2->write();
        $page2->publish('Stage', 'Live');

        $this->session()->inst_set('loggedInAs', null);

        $this->assertDOSEquals(array(
            array('Title' => 'Testpage1')
        ), GoogleSitemap::get_items(SiteTree::class), "There should be only 1 page, other is logged in only");
    }

    public function testAccess()
    {
        Config::inst()->update('GoogleSitemap', 'enabled', true);

        $response = $this->get('sitemap.xml');

        $this->assertEquals(200, $response->getStatusCode(), 'Sitemap returns a 200 success when enabled');
        $this->assertEquals('application/xml; charset="utf-8"', $response->getHeader('Content-Type'));

        GoogleSitemap::register_dataobject("GoogleSitemapTest_DataObject");
        $response = $this->get('sitemap.xml/sitemap/GoogleSitemapTest_DataObject/1');
        $this->assertEquals(200, $response->getStatusCode(), 'Sitemap returns a 200 success when enabled');
        $this->assertEquals('application/xml; charset="utf-8"', $response->getHeader('Content-Type'));

        Config::inst()->remove('GoogleSitemap', 'enabled');
        Config::inst()->update('GoogleSitemap', 'enabled', false);

        $response = $this->get('sitemap.xml');
        $this->assertEquals(404, $response->getStatusCode(), 'Sitemap index returns a 404 when disabled');

        $response = $this->get('sitemap.xml/sitemap/GoogleSitemapTest_DataObject/1');
        $this->assertEquals(404, $response->getStatusCode(), 'Sitemap file returns a 404 when disabled');
    }

    public function testDecoratorAddsFields()
    {
        $page = $this->objFromFixture(SiteTree::class, 'Page1');

        $fields = $page->getSettingsFields();
        $tab = $fields->fieldByName('Root')->fieldByName('Settings')->fieldByName('GoogleSitemap');

        $this->assertInstanceOf(Tab::class, $tab);
        $this->assertInstanceOf(DropdownField::class, $tab->fieldByName('Priority'));
        $this->assertInstanceOf(LiteralField::class, $tab->fieldByName('GoogleSitemapIntro'));
    }

    public function testGetPriority()
    {
        $page = $this->objFromFixture(SiteTree::class, 'Page1');

        // invalid field doesn't break google
        $page->Priority = 'foo';
        $this->assertEquals(0.5, $page->getGooglePriority());

        // custom value (set as string as db field is varchar)
        $page->Priority = '0.2';
        $this->assertEquals(0.2, $page->getGooglePriority());

        // -1 indicates that we should not index this
        $page->Priority = -1;
        $this->assertFalse($page->getGooglePriority());
    }

    public function testUnpublishedPage()
    {
        $orphanedPage = new SiteTree();
        $orphanedPage->ParentID = 999999; // missing parent id
        $orphanedPage->write();
        $orphanedPage->publish("Stage", "Live");

        $rootPage = new SiteTree();
        $rootPage->ParentID = 0;
        $rootPage->write();
        $rootPage->publish("Stage", "Live");

        $oldMode = Versioned::get_reading_mode();
        Versioned::set_reading_mode('Live');

        try {
            $this->assertEmpty($orphanedPage->hasPublishedParent());
            $this->assertEmpty($orphanedPage->canIncludeInGoogleSitemap());
            $this->assertNotEmpty($rootPage->hasPublishedParent());
            $this->assertNotEmpty($rootPage->canIncludeInGoogleSitemap());
        } catch (Exception $ex) {
            Versioned::set_reading_mode($oldMode);
            throw $ex;
        } // finally {
            Versioned::set_reading_mode($oldMode);
        // }
    }
}

/**
 * @package googlesitemaps
 * @subpackage tests
 */
class GoogleSitemapTest_DataObject extends DataObject implements TestOnly
{

    private static $db = array(
        'Priority' => 'Varchar(10)'
    );

    public function canView($member = null)
    {
        return true;
    }

    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }
}

/**
 * @package googlesitemaps
 * @subpackage tests
 */
class GoogleSitemapTest_OtherDataObject extends DataObject implements TestOnly
{

    private static $db = array(
        'Priority' => 'Varchar(10)'
    );

    public function canView($member = null)
    {
        return true;
    }

    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }
}

/**
 * @package googlesitemaps
 * @subpackage tests
 */
class GoogleSitemapTest_UnviewableDataObject extends DataObject implements TestOnly
{

    private static $db = array(
        'Priority' => 'Varchar(10)'
    );

    public function canView($member = null)
    {
        return false;
    }

    public function AbsoluteLink()
    {
        return Director::absoluteBaseURL();
    }
}
