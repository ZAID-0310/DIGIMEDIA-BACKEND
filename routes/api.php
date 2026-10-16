<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\RolController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PermisoController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\ModalesController;
use App\Http\Controllers\Api\TarjetaController;
use App\Http\Controllers\Api\BlogBodyController;
use App\Http\Controllers\Api\BlogHeadController;
use App\Http\Controllers\Api\EmpleadoController;
use App\Http\Controllers\Api\ModalWatController;
use App\Http\Controllers\Api\ModalMailController;
use App\Http\Controllers\Api\ServiciosController;
use App\Http\Controllers\Api\BlogFooterController;
use App\Http\Controllers\Api\ContactanosController;
use App\Http\Controllers\Api\ReclamacionesController;
use App\Http\Controllers\Api\ConsejoController;
use App\Http\Controllers\ImagePathController;
use App\Http\Controllers\Api\BlogAuditoriaController;
use App\Http\Controllers\Api\MetricasController;
use App\Http\Controllers\Api\WhatsAppCampaignController;
use App\Http\Controllers\Api\PlantillasWhatsappController;
use App\Http\Controllers\Api\PlantillasEmailController;
use App\Http\Controllers\Api\PopupConfigController;
use App\Http\Controllers\Api\SubservicioController;
use App\Http\Controllers\Api\CampaniasController;
use App\Http\Controllers\Api\ConfiguracionTiempoController;
use App\Http\Controllers\Api\TestimonioController;

// ============================================================
// RUTAS PÚBLICAS (sin autenticación)
// ============================================================

Route::middleware('throttle:5,1')->post('/register', [AuthController::class, 'register']);
Route::middleware('throttle:5,1')->post('/login', [AuthController::class, 'login'])->name('login');
Route::middleware('throttle:5,1')->post('/reset_password', [AuthController::class, "forgotPassword"]);
Route::middleware('throttle:5,1')->post('/update_password', [AuthController::class, "updatePassword"]);

Route::middleware('throttle:10,1')->post('/contactanos', [ContactanosController::class, "create"]);
Route::middleware('throttle:10,1')->post('/reclamaciones', [ReclamacionesController::class, "create"]);
Route::middleware('throttle:10,1')->post('/modales', [ModalesController::class, "create"]);

// Blogs públicos
Route::get('/cards_public', [CardController::class, "index_public"]);
Route::get('/blogs/{id}', [BlogController::class, "show"]);
Route::get('/blogs/links/{link}', [BlogController::class, 'showLink']);
Route::middleware('throttle:60,1')->get('/blogs', [BlogController::class, "index"]);
Route::get('/blog_head/{id}', [BlogHeadController::class, "show"]);
Route::get('/blog_footer/{id}', [BlogFooterController::class, "show"]);
Route::get('/blog_body/{id}', [BlogBodyController::class, "show"]);

//Testimonio crud
Route::middleware('throttle:60,1')->get('/testimonios', [TestimonioController::class, 'indexPublic']);

// Pop-ups públicos
// Slug PRIMERO para evitar colisión con {id_subservicio}
Route::get('/public/popup-configs/subservicio/slug/{slug}', [PopupConfigController::class, 'showBySubservicioSlugPublic']);
Route::get('/public/popup-configs/subservicio/{id_subservicio}', [PopupConfigController::class, 'showBySubservicioPublic']);
Route::get('/public/popup-configs/servicio/{id_servicio}', [PopupConfigController::class, 'showByServicioPublic']);

Route::get('/modales/send_wat/{id}', [ModalWatController::class, "sendWat"]);

// Plantillas - ACCESO CON API KEY (para whatsapp-service)
Route::middleware('api.key')->group(function () {
    // Subservicio PRIMERO para evitar colisión con {id_servicio}
    Route::get('/plantillas/whatsapp/subservicio/{id_subservicio}/{numero_plantilla}', [PlantillasWhatsappController::class, 'showBySubservicioNumero']);
    Route::get('/plantillas/whatsapp/{id_servicio}/{numero_plantilla}', [PlantillasWhatsappController::class, 'showByServicioNumero']);
    Route::get('/plantillas/email/{id_servicio}/{numero_plantilla}', [PlantillasEmailController::class, 'showByServicioNumero']);
});

// ============================================================
// RUTAS AUTENTICADAS (requieren token)
// ============================================================

Route::middleware('auth:sanctum')->group(function () {
    
    // Autenticación
    Route::middleware('permission:crear-empleados')->post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/empleados/verify-password', [EmpleadoController::class, 'verifyPassword']);

    // Imágenes de perfil
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/empleados/{id}/upload-signature', [EmpleadoController::class, 'generateUploadSignature']);
        Route::post('/empleados/{id}/image', [EmpleadoController::class, 'updateProfileImage']);
        Route::delete('/empleados/{id}/image', [EmpleadoController::class, 'deleteProfileImage']);
    });
    
    // Gets con permisos (administrativos)
    Route::middleware('permission:ver-blogs')->get('/cards', [CardController::class, "index"]);
    Route::middleware('permission:ver-contactos')->get('/contactanos', [ContactanosController::class, "get"]);
    Route::middleware('permission:ver-reclamaciones')->get('/reclamaciones', [ReclamacionesController::class, "get"]);
    Route::middleware('permission:ver-modales')->get('/modales', [ModalesController::class, "get"]);
    Route::middleware('permission:ver-servicios')->get('/servicios', [ServiciosController::class, "get"]);
    Route::middleware('permission:ver-blogs')->get('/cards/blog/{id?}', [CardController::class, "get"]);
    Route::middleware('permission:ver-blogs')->get('/blogs_auditoria', [BlogAuditoriaController::class, 'show']);
    Route::middleware('permission:ver-blogs')->get('/consejos/{id}', [ConsejoController::class, "showAll"]);
    Route::middleware('permission:ver-contactos')->get('/contactanos/{id}', [ContactanosController::class, "getById"]);
    Route::middleware('permission:ver-reclamaciones')->get('/reclamaciones/{id}', [ReclamacionesController::class, "getById"]);
    Route::middleware('permission:ver-modales')->get('/modales/{id}', [ModalesController::class, "getById"]);

    // Emails y mensajes
    Route::middleware('permission:ver-modales')->get('/modales/modals_emails_wats/{id}', [ModalesController::class, "getSendModales"]);
    Route::middleware('permission:enviar-mensajes', 'throttle:10,1')->get('/modales/send_mail/{id}', [ModalMailController::class, "sendMail"]);
    Route::middleware('permission:enviar-mensajes', 'throttle:10,1')->put('/modales/reportar_error/{id}', [ModalMailController::class, "reportarError"]);
    Route::middleware('permission:enviar-mensajes', 'throttle:10,1')->put('/modales/estado_wat/{id}', [ModalWatController::class, "cambiarEstado"]);

    // Updates (administrativos)
    Route::middleware('permission:editar-contactos')->put('/contactanos/{id}', [ContactanosController::class, "update"]);
    Route::middleware('permission:editar-servicios')->put('/servicios/{id}', [ServiciosController::class, "update"]);
    Route::middleware('permission:editar-reclamaciones')->put('/reclamaciones/{id}', [ReclamacionesController::class, "update"]);
    Route::middleware('permission:editar-modales')->put('/modales/{id}', [ModalesController::class, "update"]);

    // Configuración de tiempos de envío (marketing y admin)
    Route::middleware('role:marketing,administrador')->group(function () {
        Route::get('/servicios/{id_servicio}/tiempos', [ConfiguracionTiempoController::class, 'get']);
        Route::put('/servicios/{id_servicio}/tiempos', [ConfiguracionTiempoController::class, 'update']);
        Route::post('/servicios/{id_servicio}/tiempos', [ConfiguracionTiempoController::class, 'store']);
        Route::delete('/servicios/{id_servicio}/tiempos/{tipo}/{numero_mensaje}', [ConfiguracionTiempoController::class, 'destroy']);
    });

    // Creates (administrativos)
    Route::middleware('permission:crear-servicios', 'throttle:60,1')->post('/servicios', [ServiciosController::class, "create"]);
    Route::middleware('permission:crear-blogs', 'throttle:60,1')->post('/card', [CardController::class, "create"]);
    Route::middleware('permission:crear-blogs', 'throttle:60,1')->post('/blog', [BlogController::class, "create"]);
    Route::middleware('permission:crear-blogs', 'throttle:60,1')->post('/blog_head', [BlogHeadController::class, "create"]);
    Route::middleware('permission:crear-blogs', 'throttle:60,1')->post('/blog_body', [BlogBodyController::class, "create"]);
    Route::middleware('permission:crear-blogs', 'throttle:60,1')->post('/blog_footer', [BlogFooterController::class, "create"]);
    Route::middleware('permission:crear-tarjetas', 'throttle:60,1')->post('/consejo', [ConsejoController::class, "create"]);
    Route::middleware('permission:crear-tarjetas', 'throttle:60,1')->post('/tarjeta', [TarjetaController::class, "create"]);
    Route::middleware('permission:crear-tarjetas', 'throttle:60,1')->post('/card/blog/image_head/{id}', [CardController::class, "imageHeader"]);
    Route::middleware('permission:crear-tarjetas', 'throttle:60,1')->post('/card/blog/images_body/{id}', [CardController::class, "imagesBody"]);
    Route::middleware('permission:crear-tarjetas', 'throttle:60,1')->post('/card/blog/images_footer/{id}', [CardController::class, "imagesFooter"]);

    // Updates blog (administrativos)
    Route::middleware('permission:editar-blogs')->put('/card/{id}', [CardController::class, "update"]);
    Route::middleware('permission:editar-blogs')->put('/blog/{id}', [BlogController::class, "update"]);
    Route::middleware('permission:editar-blogs')->put('/blog_head/{id}', [BlogHeadController::class, "update"]);
    Route::middleware('permission:editar-blogs')->put('/blog_body/{id}', [BlogBodyController::class, "update"]);
    Route::middleware('permission:editar-blogs')->put('/blog_footer/{id}', [BlogFooterController::class, "update"]);
    Route::middleware('permission:editar-blogs')->put('/consejo/{id}', [ConsejoController::class, "update"]);
    Route::middleware('permission:editar-blogs')->put('/tarjeta/{id}', [TarjetaController::class, "update"]);

    // Deletes blog (administrativos)
    Route::middleware('permission:eliminar-blogs')->delete('/cards/{id}', [CardController::class, "destroy"]);
    Route::middleware('permission:eliminar-blogs')->delete('/blogs/{id}', [BlogController::class, "destroy"]);
    Route::middleware('permission:eliminar-blogs')->delete('/blog_head/{id}', [BlogHeadController::class, "destroy"]);
    Route::middleware('permission:eliminar-blogs')->delete('/blog_body/{id}', [BlogBodyController::class, "destroy"]);
    Route::middleware('permission:eliminar-blogs')->delete('/blog_footer/{id}', [BlogFooterController::class, "destroy"]);
    Route::middleware('permission:eliminar-tarjetas')->delete('/consejo/{id}', [ConsejoController::class, "destroy"]);
    Route::middleware('permission:eliminar-tarjetas')->delete('/consejos_delete/{id}', [ConsejoController::class, "destroyAll"]);
    Route::middleware('permission:eliminar-tarjetas')->delete('/tarjetas_delete/{id}', [TarjetaController::class, "destroyAll"]);
    Route::middleware('permission:editar-blogs')->delete('/delete_carpet/{id}', [CardController::class, "deleteCarpetaImages"]);

    // Deletes generales (administrativos)
    Route::middleware('permission:eliminar-contactos')->delete('/contactanos/{id}', [ContactanosController::class, "delete"]);
    Route::middleware('permission:eliminar-reclamaciones')->delete('/reclamaciones/{id}', [ReclamacionesController::class, "delete"]);
    Route::middleware('permission:eliminar-modales')->delete('/modales/{id}', [ModalesController::class, "delete"]);
    Route::middleware('permission:eliminar-servicios')->delete('/servicios/{id}', [ServiciosController::class, "delete"]);

    // Empleados (administrativos)
    Route::middleware('permission:ver-empleados', 'throttle:60,1')->get('/empleados', [EmpleadoController::class, "getAllByPage"]);
    Route::middleware('permission:ver-empleados')->get('/empleados/{id}', [EmpleadoController::class, "getById"]);
    Route::middleware('permission:crear-empleados', 'throttle:60,1')->post('/empleados', [EmpleadoController::class, "create"]);
    Route::middleware('permission:editar-empleados')->put('/empleados/{id}', [EmpleadoController::class, "update"]);
    Route::put('/empleados/pass/{id}', [EmpleadoController::class, "updatePass"]);
    Route::middleware('permission:eliminar-empleados')->delete('/empleados/{id}', [EmpleadoController::class, "delete"]);

    // Roles y permisos (solo admin)
    Route::middleware('permission:ver-roles')->get('/roles', [RolController::class, "index"]);
    Route::middleware('permission:crear-roles')->post('/roles', [RolController::class, "store"]);
    Route::middleware('permission:ver-roles')->get('/roles/{id}', [RolController::class, "show"]);
    Route::middleware('permission:editar-roles')->put('/roles/{id}', [RolController::class, "update"]);
    Route::middleware('permission:eliminar-roles')->delete('/roles/{id}', [RolController::class, "destroy"]);
    Route::middleware('permission:ver-permisos')->get('/roles/{id}/permisos', [RolController::class, "getPermisos"]);
    Route::middleware('permission:ver-permisos')->post('/roles/{id}/permisos', [RolController::class, "syncPermisos"]);

    // Permisos (solo admin)
    Route::middleware('permission:ver-permisos')->get('/permisos', [PermisoController::class, "index"]);
    Route::middleware('permission:ver-permisos')->get('/permisos/{id}', [PermisoController::class, "show"]);
    Route::middleware('permission:crear-permisos')->post('/permisos', [PermisoController::class, "store"]);
    Route::middleware('permission:editar-permisos')->put('/permisos/{id}', [PermisoController::class, "update"]);
    Route::middleware('permission:eliminar-permisos')->delete('/permisos/{id}', [PermisoController::class, "destroy"]);

    // Métricas (requieren ver-blogs)
    Route::middleware('permission:ver-blogs')->get('/metrics/count_blogs_by_month', [MetricasController::class, "countBlogsByMonth"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/list_blogs_by_months_12', [MetricasController::class, "listBlogsByMonths12"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/top5_months_with_more_blogs', [MetricasController::class, "top5MothsWithMoreBlogs"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/cards_by_plantilla', [MetricasController::class, "listOfCardsByPlantilla"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/count_cards_by_plantilla', [MetricasController::class, "countListOfCardsByPlantilla"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/count_total_cards', [MetricasController::class, "tableCardsByIdPlantilla"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/list_empleado_cards', [MetricasController::class, "listEmpleadoWithCards"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/count_cards_by_empleado', [MetricasController::class, "countListOfCardsByEmpleado"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/count_total_cards_by_empleado', [MetricasController::class, "tableCardsByEmpleado"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/frecuencia_publicacion_cards_todos_empleados', [MetricasController::class, "frecuenciaPublicacionCardsTodosEmpleados"]);
    Route::middleware('permission:ver-blogs')->get('/metrics/tiempo_creacion_edicion_publicacion_card', [MetricasController::class, "tiempoCreacionEdicionPublicacionCard"]);
    
    // Testimonios (panel admin)
    Route::middleware('permission:ver-testimonios')->get('/testimonios/panel', [TestimonioController::class, 'index']);
    Route::middleware('permission:ver-testimonios')->get('/testimonios/panel/{id}', [TestimonioController::class, 'show']);
    Route::middleware('permission:crear-testimonios')->post('/testimonios', [TestimonioController::class, 'store']);
    Route::middleware('permission:editar-testimonios')->put('/testimonios/{id}', [TestimonioController::class, 'update']);
    Route::middleware('permission:eliminar-testimonios')->delete('/testimonios/{id}', [TestimonioController::class, 'destroy']);

    // Testimonios (imagen vía Cloudinary)
    Route::middleware('permission:crear-testimonios,editar-testimonios')->post('/testimonios/{id}/upload-signature', [TestimonioController::class, 'generateUploadSignature']);
    Route::middleware('permission:crear-testimonios,editar-testimonios')->post('/testimonios/{id}/image', [TestimonioController::class, 'updateImage']);
    Route::middleware('permission:editar-testimonios')->delete('/testimonios/{id}/image', [TestimonioController::class, 'deleteImage']);
    Route::middleware('permission:editar-testimonios')->put('/testimonios/{id}/toggle-activo', [TestimonioController::class, 'toggleActivo']);
    
    // Campañas de WhatsApp (marketing y admin)
    Route::middleware('role:marketing,administrador')->group(function () {
        Route::get('/whatsapp/campaign/preview/{service}', [WhatsAppCampaignController::class, 'previewCampaign']);
        Route::post('/whatsapp/campaign/create', [WhatsAppCampaignController::class, 'createCampaign']);
        Route::post('/whatsapp/campaign/{id}/start', [WhatsAppCampaignController::class, 'startCampaign']);
        Route::post('/whatsapp/campaign/{id}/pause', [WhatsAppCampaignController::class, 'pauseCampaign']);
        Route::post('/whatsapp/campaign/{id}/resume', [WhatsAppCampaignController::class, 'resumeCampaign']);
        Route::post('/whatsapp/campaign/{id}/cancel', [WhatsAppCampaignController::class, 'cancelCampaign']);
        Route::get('/whatsapp/campaign/{id}/status', [WhatsAppCampaignController::class, 'getCampaignStatus']);
        Route::get('/whatsapp/campaigns', [WhatsAppCampaignController::class, 'listCampaigns']);
    });

    // Campañas - historial (marketing y admin)
    Route::middleware('role:marketing,administrador')->group(function () {
        Route::get('/campanias', [CampaniasController::class, 'index']);
        Route::get('/campanias/{id}', [CampaniasController::class, 'show']);
        Route::get('/campanias/{id}/leads', [CampaniasController::class, 'leads']);
        Route::post('/campanias/{id}/leads/{wat_modal_id}/retry', [CampaniasController::class, 'retryLead']);
    });

    // Plantillas (marketing y admin)
    Route::middleware('role:marketing,administrador')->group(function () {
        Route::get('/plantillas/whatsapp', [PlantillasWhatsappController::class, 'index']);
        // by-owner debe ir ANTES que {id} para evitar colisión de rutas
        Route::get('/plantillas/whatsapp/by-owner/{type}/{id}', [PlantillasWhatsappController::class, 'showByOwner']);
        Route::post('/plantillas/whatsapp/by-owner/{type}/{id}/init', [PlantillasWhatsappController::class, 'inicializar']);
        Route::get('/plantillas/whatsapp/{id}', [PlantillasWhatsappController::class, 'show']);
        Route::post('/plantillas/whatsapp/{id}/actualizar', [PlantillasWhatsappController::class, 'actualizar']);
        Route::get('/plantillas/email', [PlantillasEmailController::class, 'index']);
        Route::get('/plantillas/email/by-owner/{type}/{id}', [PlantillasEmailController::class, 'showByOwner']);
        Route::post('/plantillas/email/by-owner/{type}/{id}/init', [PlantillasEmailController::class, 'inicializar']);
        Route::get('/plantillas/email/{id}', [PlantillasEmailController::class, 'show']);
        Route::post('/plantillas/email/{id}/actualizar', [PlantillasEmailController::class, 'actualizar']);
    });
    
    // Servicios, Subservicios y Pop-Ups (marketing y admin)
    Route::middleware('role:marketing,administrador')->group(function () {
        // Servicios
        Route::get('/servicios', [ServiciosController::class, 'get']);
        // Subservicios
        Route::get('/subservicios', [SubservicioController::class, 'index']);
        Route::get('/subservicios/by-servicio/{id_servicio}', [SubservicioController::class, 'byServicio']);
        // Pop-Ups
        Route::get('/popup-configs', [PopupConfigController::class, 'index']);
        Route::get('/popup-configs/{id}', [PopupConfigController::class, 'show']);
        Route::get('/popup-configs/subservicio/{id_subservicio}', [PopupConfigController::class, 'showBySubservicio']);
        Route::post('/popup-configs', [PopupConfigController::class, 'store']);
        Route::post('/popup-configs/{id}/actualizar', [PopupConfigController::class, 'update']);
        Route::delete('/popup-configs/{id}', [PopupConfigController::class, 'destroy']);

    });

    // fixes
});