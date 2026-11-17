<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\StoreBlogRequest;
use App\Http\Requests\Blog\UpdateBlogRequest;
use App\Http\Resources\BlogResource;
use App\Services\Blog\BlogService;
use App\Services\Blog\BlogHeadService;
use App\Services\BlogBodyService;
use App\DTOs\BlogBody\UpdateBlogBodyDTO;
use App\DTOs\BlogHead\UpdateBlogHeadDTO;
use App\DTOs\Blog\FiltrosBlogDTO;
use App\Traits\AuthorizesBlogAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class BlogController extends Controller
{
    use AuthorizesBlogAccess;

    public function __construct(
        private BlogService $blogService,
        private BlogHeadService $blogHeadService,
        private BlogBodyService $blogBodyService
    ) {}

    public function index()
    {
        try {
            $onlyPublished = !$this->canViewUnpublishedBlogs();
            $blogs = $this->blogService->getAllBlogs($onlyPublished);
            return BlogResource::collection($blogs);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function blogByMonthYear(Request $request)
    {
        try {
            $onlyPublished = !$this->canViewUnpublishedBlogs();
            $filters = FiltrosBlogDTO::fromRequest($request);
            $blogs = $this->blogService->getBlogsByFilters($filters, $onlyPublished);
            return response()->json($blogs, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function create(StoreBlogRequest $request)
    {
        try {
            $blog = $this->blogService->createBlog($request->validated());
            
            return response()->json([
                "status" => 200,
                "message" => "Blog creado correctamente",
                "id" => $blog->id_blog,
                "link" => $blog->link,
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(UpdateBlogRequest $request, int $id)
    {
        try {
            $validated = $request->validated();
            $blog = $this->blogService->getBlogById($id);

            if ($this->shouldUpdateBlogHead($validated)) {
                $headData = array_merge(
                    $blog->head->only($this->blogHeadFields()),
                    Arr::only($validated, $this->blogHeadFields())
                );
                $this->blogHeadService->update($blog->id_blog_head, UpdateBlogHeadDTO::fromArray($headData));
            }

            if ($this->shouldUpdateBlogBody($validated)) {
                $bodyData = array_merge(
                    $blog->body->only($this->blogBodyFields()),
                    Arr::only($validated, $this->blogBodyFields())
                );
                $this->blogBodyService->update($blog->id_blog_body, UpdateBlogBodyDTO::fromArray($bodyData));
            }

            if ($this->shouldUpdateBlogRecord($validated)) {
                $blog = $this->blogService->updateBlog($id, Arr::only($validated, ['id_blog_head', 'id_blog_body', 'id_blog_footer', 'fecha', 'id_empleado', 'descripcion']));
            } else {
                $blog = $blog->refresh();
            }

            return response()->json([
                'status' => 200,
                'message' => 'Blog actualizado',
                'id' => $blog->id_blog,
                'link' => $blog->link,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Blog no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function shouldUpdateBlogHead(array $validated): bool
    {
        return Arr::hasAny($validated, [
            'texto_frase',
            'texto_descripcion',
            'public_image',
            'url_image',
            'alt',
            'title',
            'meta_title',
            'meta_descripcion',
        ]);
    }

    private function shouldUpdateBlogBody(array $validated): bool
    {
        $bodySpecific = Arr::hasAny($validated, [
            'descripcion',
            'public_image1',
            'url_image1',
            'alt_image1',
            'title_image1',
            'public_image2',
            'url_image2',
            'alt_image2',
            'title_image2',
            'public_image3',
            'url_image3',
            'alt_image3',
            'title_image3',
            'flag_galeria',
            'flag_consejos',
            'flag_informacion',
            'service_url',
            'titulo_tarjeta',
            'titulo_consejos',
        ]);

        return $bodySpecific || Arr::hasAny($validated, ['titulo', 'descripcion']) && !$this->shouldUpdateBlogHead($validated);
    }

    private function shouldUpdateBlogRecord(array $validated): bool
    {
        return Arr::hasAny($validated, ['id_blog_head', 'id_blog_body', 'id_blog_footer', 'fecha', 'id_empleado']);
    }

    private function blogHeadFields(): array
    {
        return [
            'titulo',
            'texto_frase',
            'texto_descripcion',
            'public_image',
            'url_image',
            'alt',
            'title',
            'meta_title',
            'meta_descripcion',
        ];
    }

    private function blogBodyFields(): array
    {
        return [
            'titulo',
            'descripcion',
            'public_image1',
            'url_image1',
            'alt_image1',
            'title_image1',
            'public_image2',
            'url_image2',
            'alt_image2',
            'title_image2',
            'public_image3',
            'url_image3',
            'alt_image3',
            'title_image3',
            'flag_galeria',
            'flag_consejos',
            'flag_informacion',
            'service_url',
            'titulo_tarjeta',
            'titulo_consejos',
        ];
    }

    public function show(int $id)
    {
        try {
            $onlyPublished = !$this->canViewUnpublishedBlogs();
            $blog = $this->blogService->getBlogById($id, $onlyPublished);

            return response()->json([
                'status' => 200,
                'data' => new BlogResource($blog)
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Blog no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function showLink(string $link)
    {
        try {
            $onlyPublished = !$this->canViewUnpublishedBlogs();
            $blog = $this->blogService->getBlogByLink($link, $onlyPublished);

            return response()->json([
                'status' => 200,
                'data' => new BlogResource($blog)
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Blog no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->blogService->deleteBlog($id);
            
            return response()->json([
                "status" => 200,
                "message" => "Blog eliminado correctamente"
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Blog no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}