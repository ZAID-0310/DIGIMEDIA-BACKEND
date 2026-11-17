<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Blog;
use App\Models\Card;
use App\Services\Blog\BlogService;
use App\Repositories\BlogRepository;
use App\Repositories\BlogHeadRepository;
use App\Services\Blog\BlogSlugService;
use App\Services\Blog\BlogCascadeDeleteService;
use App\Services\Blog\BlogStorageService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;

class BlogAccessControlTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_blog_service_calls_get_all_public_when_only_published_is_true(): void
    {
        $repo = Mockery::mock(BlogRepository::class);
        $headRepo = Mockery::mock(BlogHeadRepository::class);
        $slugService = Mockery::mock(BlogSlugService::class);
        $cascadeService = Mockery::mock(BlogCascadeDeleteService::class);
        $storageService = Mockery::mock(BlogStorageService::class);

        $repo->shouldReceive('getAllPublic')
            ->once()
            ->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $service = new BlogService($repo, $headRepo, $slugService, $cascadeService, $storageService);
        $result = $service->getAllBlogs(true);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $result);
    }

    public function test_blog_service_calls_get_all_when_only_published_is_false(): void
    {
        $repo = Mockery::mock(BlogRepository::class);
        $headRepo = Mockery::mock(BlogHeadRepository::class);
        $slugService = Mockery::mock(BlogSlugService::class);
        $cascadeService = Mockery::mock(BlogCascadeDeleteService::class);
        $storageService = Mockery::mock(BlogStorageService::class);

        $repo->shouldReceive('getAll')
            ->once()
            ->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $service = new BlogService($repo, $headRepo, $slugService, $cascadeService, $storageService);
        $result = $service->getAllBlogs(false);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $result);
    }

    public function test_blog_service_throws_not_found_when_public_blog_does_not_exist_or_is_unpublished(): void
    {
        $repo = Mockery::mock(BlogRepository::class);
        $headRepo = Mockery::mock(BlogHeadRepository::class);
        $slugService = Mockery::mock(BlogSlugService::class);
        $cascadeService = Mockery::mock(BlogCascadeDeleteService::class);
        $storageService = Mockery::mock(BlogStorageService::class);

        $repo->shouldReceive('findPublicById')
            ->with(99)
            ->once()
            ->andReturn(null);

        $service = new BlogService($repo, $headRepo, $slugService, $cascadeService, $storageService);

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('Blog no encontrado');

        $service->getBlogById(99, true);
    }

    public function test_blog_service_throws_not_found_when_public_blog_by_link_is_unpublished(): void
    {
        $repo = Mockery::mock(BlogRepository::class);
        $headRepo = Mockery::mock(BlogHeadRepository::class);
        $slugService = Mockery::mock(BlogSlugService::class);
        $cascadeService = Mockery::mock(BlogCascadeDeleteService::class);
        $storageService = Mockery::mock(BlogStorageService::class);

        $repo->shouldReceive('findPublicByLink')
            ->with('borrador-secreto')
            ->once()
            ->andReturn(null);

        $service = new BlogService($repo, $headRepo, $slugService, $cascadeService, $storageService);

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage('Blog no encontrado');

        $service->getBlogByLink('borrador-secreto', true);
    }
}

