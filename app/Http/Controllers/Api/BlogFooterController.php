<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlogFooter\StoreBlogFooterRequest;
use App\Http\Requests\BlogFooter\UpdateBlogFooterRequest;
use App\Http\Resources\BlogFooterResource;
use App\Services\BlogFooterService;
use App\DTOs\BlogFooter\CreateBlogFooterDTO;
use App\DTOs\BlogFooter\UpdateBlogFooterDTO;
use App\Traits\AuthorizesBlogAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BlogFooterController extends Controller
{
    use AuthorizesBlogAccess;
    public function __construct(
        private BlogFooterService $blogFooterService
    ) {}

    public function create(StoreBlogFooterRequest $request)
    {
        try {
            $dto = CreateBlogFooterDTO::fromRequest($request);
            $blogFooter = $this->blogFooterService->create($dto);

            return response()->json([
                "status" => 200,
                "message" => "BlogFooter creado correctamente",
                "id" => $blogFooter->id_blog_footer
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "status" => 500,
                "message" => "Error interno del servidor",
                "error" => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function update(UpdateBlogFooterRequest $request, int $id)
    {
        try {
            $dto = UpdateBlogFooterDTO::fromRequest($request);
            $blogFooter = $this->blogFooterService->update($id, $dto);

            return response()->json([
                'status' => 200,
                'message' => 'BlogFooter actualizado',
                'id' => $blogFooter->id_blog_footer
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    public function show(int $id)
    {
        try {
            $blogFooter = $this->blogFooterService->findById($id);

            if (!$this->canViewUnpublishedBlogs()) {
                $blog = $blogFooter->blog;
                $isPublished = $blog && $blog->card && $blog->card->estado_publicacion;
                if (!$isPublished) {
                    return response()->json([
                        "status" => 404,
                        "message" => "BlogFooter no encontrado"
                    ], 404);
                }
            }

            return response()->json([
                "status" => 200,
                "data" => new BlogFooterResource($blogFooter)
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                "status" => 404,
                "message" => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                "status" => 500,
                "message" => "Error interno del servidor",
                "error" => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->blogFooterService->delete($id);

            return response()->json([
                "status" => 200,
                "message" => "BlogFooter eliminado correctamente"
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                "status" => 404,
                "message" => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                "status" => 500,
                "message" => "Error al eliminar el blogFooter",
                "error" => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}