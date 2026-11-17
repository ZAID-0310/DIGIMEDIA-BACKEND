<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlogBody\StoreBlogBodyRequest;
use App\Http\Requests\BlogBody\UpdateBlogBodyRequest;
use App\Http\Resources\BlogBodyResource;
use App\Services\BlogBodyService;
use App\DTOs\BlogBody\CreateBlogBodyDTO;
use App\DTOs\BlogBody\UpdateBlogBodyDTO;
use App\Traits\AuthorizesBlogAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BlogBodyController extends Controller
{
    use AuthorizesBlogAccess;
    public function __construct(
        private BlogBodyService $blogBodyService
    ) {}

    public function create(StoreBlogBodyRequest $request)
    {
        try {
            $dto = CreateBlogBodyDTO::fromRequest($request);
            $blogBody = $this->blogBodyService->create($dto);

            return response()->json([
                "status" => 200,
                "message" => "BlogBody creado correctamente",
                "id" => $blogBody->id_blog_body
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "status" => 500,
                "message" => "Error al crear el blogBody",
                "error" => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function update(UpdateBlogBodyRequest $request, int $id)
    {
        try {
            $dto = UpdateBlogBodyDTO::fromRequest($request);
            $blogBody = $this->blogBodyService->update($id, $dto);

            return response()->json([
                'status' => 200,
                'message' => 'Blog Body actualizado',
                'id' => $blogBody->id_blog_body
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

    public function show(int $id)
    {
        try {
            $blogBody = $this->blogBodyService->findById($id);

            if (!$this->canViewUnpublishedBlogs()) {
                $blog = $blogBody->blog;
                $isPublished = $blog && $blog->card && $blog->card->estado_publicacion;
                if (!$isPublished) {
                    return response()->json([
                        "status" => 404,
                        "message" => "BlogBody no encontrado"
                    ], 404);
                }
            }

            return response()->json([
                "status" => 200,
                "data" => new BlogBodyResource($blogBody)
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                "status" => 404,
                "message" => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                "status" => 500,
                "message" => "Error interno",
                "error" => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->blogBodyService->delete($id);

            return response()->json([
                "status" => 200,
                "message" => "BlogBody eliminada correctamente"
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                "status" => 404,
                "message" => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                "status" => 500,
                "message" => "Error al eliminar el BlogBody",
                "error" => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}