<?php

namespace App\Repositories;

use App\Models\Blog;
use App\DTOs\Blog\CreateBlogDTO;
use App\DTOs\Blog\UpdateBlogDTO;
use App\DTOs\Blog\FiltrosBlogDTO;
use Illuminate\Database\Eloquent\Collection;

class BlogRepository
{
    public function getAll(): Collection
    {
        return Blog::completo()->reciente()->get();
    }

    public function getAllPublic(): Collection
    {
        return Blog::publicados()->completo()->reciente()->get();
    }

    public function findById(int $id): ?Blog
    {
        return Blog::completo()->find($id);
    }

    public function findPublicById(int $id): ?Blog
    {
        return Blog::publicados()->completo()->find($id);
    }

    public function findByLink(string $link): ?Blog
    {
        return Blog::completo()->where('link', $link)->first();
    }

    public function findPublicByLink(string $link): ?Blog
    {
        return Blog::publicados()->completo()->where('link', $link)->first();
    }

    public function create(CreateBlogDTO $dto): Blog
    {
        return Blog::create($dto->toArray());
    }

    public function update(Blog $blog, UpdateBlogDTO $dto): bool
    {
        return $blog->update($dto->toArray());
    }

    public function getByFilters(FiltrosBlogDTO $filters, bool $onlyPublished = false): Collection
    {
        $query = Blog::completo();
        
        if ($onlyPublished) {
            $query->publicados();
        }

        if ($filters->month !== null) {
            $query->whereMonth('fecha', $filters->month);
        }
        
        if ($filters->year !== null) {
            $query->whereYear('fecha', $filters->year);
        }
        
        return $query->get();
    }

    public function delete(Blog $blog): bool
    {
        return $blog->delete();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Blog::where('link', $slug);
        
        if ($excludeId) {
            $query->where('id_blog', '!=', $excludeId);
        }
        
        return $query->exists();
    }
}