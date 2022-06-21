<?php

namespace Tests\StaticCaching;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Replacer;
use Statamic\View\Cascade;
use Symfony\Component\HttpFoundation\Response;
use Tests\FakesContent;
use Tests\FakesViews;
use Tests\PreventSavingStacheItemsToDisk;
use Tests\TestCase;

class HalfMeasureStaticCachingTest extends TestCase
{
    use FakesContent;
    use FakesViews;
    use PreventSavingStacheItemsToDisk;

    public function setUp(): void
    {
        parent::setUp();

        // During these tests, we're doing multiple requests, so we want to make it act like
        // fresh requests. Within a single test, the cascade would normally hang around,
        // but we don't want the second request to use the cascade from the first, so we'll reset it.
        // We don't want to reset it for the whole app (not yet, anyway) since that breaks some stuff.
        Event::listen(function (RequestHandled $event) {
            $this->app->offsetUnset(Cascade::class);
            Facade::clearResolvedInstance(Cascade::class);

            // Exact same as in ViewServiceProvider
            $this->app->singleton(Cascade::class, function ($app) {
                return new Cascade($app['request'], Site::current());
            });
        });
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.static_caching.strategy', 'half');
        $app['config']->set('statamic.static_caching.replacers', [TestReplacer::class]);
    }

    /** @test */
    public function it_statically_caches()
    {
        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '<h1>{{ title }}</h1> {{ content }}');

        $page = $this->createPage('about', [
            'with' => [
                'title' => 'The About Page',
                'content' => 'This is the about page.',
            ],
        ]);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> <p>This is the about page.</p>', false);

        $page
            ->set('content', 'Updated content')
            ->saveQuietly(); // Save quietly to prevent the invalidator from clearing the statically cached page.

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> <p>This is the about page.</p>', false);
    }

    /** @test */
    public function it_performs_replacements()
    {
        Carbon::setTestNow(Carbon::parse('2019-01-01'));

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '{{ now format="Y-m-d" }} REPLACEME');

        $this->createPage('about');

        $response = $this->get('/about')->assertOk();
        $this->assertSame('2019-01-01 INITIAL-2019-01-01', $response->getContent());

        Carbon::setTestNow(Carbon::parse('2020-05-23'));
        $response = $this->get('/about')->assertOk();
        $this->assertSame('2019-01-01 SUBSEQUENT-2020-05-23', $response->getContent());
    }

    /** @test */
    public function it_can_keep_parts_dynamic_using_nocache_tags()
    {
        // Use a tag that outputs something dynamic.
        // It will just increment by one every time it's used.

        app()->instance('example_count', 0);

        (new class extends \Statamic\Tags\Tags
        {
            public static $handle = 'example_count';

            public function index()
            {
                $count = app('example_count');
                $count++;
                app()->instance('example_count', $count);

                return $count;
            }
        })::register();

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '{{ example_count }} {{ nocache }}{{ example_count }}{{ /nocache }}');

        $this->createPage('about');

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('1 2', false);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('1 3', false);
    }

    /** @test */
    public function it_can_keep_the_cascade_parts_dynamic_using_nocache_tags()
    {
        // The "now" variable is generated in the cascade on every request.

        Carbon::setTestNow(Carbon::parse('2019-01-01'));

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '{{ now format="Y-m-d" }} {{ nocache }}{{ now format="Y-m-d" }}{{ /nocache }}');

        $this->createPage('about');

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('2019-01-01 2019-01-01', false);

        Carbon::setTestNow(Carbon::parse('2020-05-23'));

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('2019-01-01 2020-05-23', false);
    }

    /** @test */
    public function it_can_keep_the_urls_page_parts_dynamic_using_nocache_tags()
    {
        // The "page" variable (i.e. the about entry) is inserted into the cascade on every request.

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', '<h1>{{ title }}</h1> {{ text }} {{ nocache }}{{ text }}{{ /nocache }}');

        $page = $this->createPage('about', [
            'with' => [
                'title' => 'The About Page',
                'text' => 'This is the about page.',
            ],
        ]);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> This is the about page. This is the about page.', false);

        $page
            ->set('text', 'Updated text')
            ->saveQuietly(); // Save quietly to prevent the invalidator from clearing the statically cached page.

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('<h1>The About Page</h1> This is the about page. Updated text', false);
    }

    /** @test */
    public function it_can_keep_parts_dynamic_using_nested_nocache_tags()
    {
        // Use a tag that outputs something dynamic.
        // It will just increment by one every time it's used.

        app()->instance('example_count', 0);

        (new class extends \Statamic\Tags\Tags
        {
            public static $handle = 'example_count';

            public function index()
            {
                $count = app('example_count');
                $count++;
                app()->instance('example_count', $count);

                return $count;
            }
        })::register();

        $template = <<<'EOT'
{{ example_count }}
{{ nocache }}
    {{ example_count }}
    {{ nocache }}
        {{ example_count }}
    {{ /nocache }}
{{ /nocache }}
EOT;

        $this->withStandardFakeViews();
        $this->viewShouldReturnRaw('default', $template);

        $this->createPage('about');

        $this
            ->get('/about')
            ->assertOk()
            ->assertSeeInOrder([1, 2, 3]);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSeeInOrder([1, 4, 5]);
    }

    public function bladeViewPaths($app)
    {
        $app['config']->set('view.paths', [
            __DIR__.'/blade',
            ...$app['config']->get('view.paths'),
        ]);
    }

    /**
     * @test
     * @define-env bladeViewPaths
     */
    public function it_can_keep_parts_dynamic_using_blade()
    {
        // Use a tag that outputs something dynamic.
        // It will just increment by one every time it's used.

        app()->instance('example_count', 0);

        app()->instance('example_count_tag', function () {
            $count = app('example_count');
            $count++;
            app()->instance('example_count', $count);

            return $count;
        });

        $this->createPage('about');

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('1 2', false);

        $this
            ->get('/about')
            ->assertOk()
            ->assertSee('1 3', false);
    }
}

class TestReplacer implements Replacer
{
    public function prepareResponseToCache(Response $response, Response $initial)
    {
        $initial->setContent(
            str_replace('REPLACEME', 'INITIAL-'.Carbon::now()->format('Y-m-d'), $initial->getContent())
        );
    }

    public function replaceInCachedResponse(Response $response)
    {
        $response->setContent(
            str_replace('REPLACEME', 'SUBSEQUENT-'.Carbon::now()->format('Y-m-d'), $response->getContent())
        );
    }
}
