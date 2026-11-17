<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Blog extends Model
{
    use HasFactory;
    protected $table = 'blogs';
    protected $primaryKey = 'id_blog';
    public $timestamps = false;

    protected $fillable = [
        'id_blog_head',
        'id_blog_body',
        'id_blog_footer',
        'fecha',
        'link'
    ];

    public function head()
    {
        return $this->belongsTo(BlogHead::class, 'id_blog_head', 'id_blog_head');
    }

    public function body()
    {
        return $this->belongsTo(BlogBody::class, 'id_blog_body', 'id_blog_body');
    }

    public function footer()
    {
        return $this->belongsTo(BlogFooter::class, 'id_blog_footer', 'id_blog_footer');
    }

    // public function card(){
    //     return $this->belongsTo(Card::class, 'id_blog', 'id_blog');
    // }
    public function card()
    {
        return $this->hasOne(Card::class, 'id_blog', 'id_blog');
    }

    public function blogAuditoria()
    {
        return $this->hasMany(BlogAuditoria::class, 'id_blog', 'id_blog');
    }

    // ============================================================
    // SCOPES para queries reutilizables
    // ============================================================

    /**
     * Filtrar solo blogs publicados (con card asociada y estado_publicacion = true)
     * Uso: Blog::publicados()->get()
     */
    public function scopePublicados($query)
    {
        return $query->whereHas('card', function ($q) {
            $q->where('estado_publicacion', true);
        });
    }

    /**
     * Filtrar solo blogs en borrador o invisibles (sin card o con estado_publicacion = false)
     * Uso: Blog::borrador()->get()
     */
    public function scopeBorrador($query)
    {
        return $query->where(function ($q) {
            $q->doesntHave('card')
              ->orWhereHas('card', function ($sub) {
                  $sub->where('estado_publicacion', false);
              });
        });
    }

    /**
     * Filtrar blogs con cards
     * Uso: Blog::conCards()->get()
     */
    public function scopeConCards($query)
    {
        return $query->has('card');
    }

    /**
     * Filtrar blogs sin cards
     * Uso: Blog::sinCards()->get()
     */
    public function scopeSinCards($query)
    {
        return $query->doesntHave('card');
    }

    /**
     * Proyectar con relaciones eager loaded
     * Uso: Blog::conRelaciones()->get()
     */
    public function scopeConRelaciones($query)
    {
        return $query->with(['head', 'body', 'footer', 'card', 'blogAuditoria']);
    }

    /**
     * Ordenar por fecha (más recientes primero)
     * Uso: Blog::reciente()->get()
     */
    public function scopeReciente($query)
    {
        return $query->orderBy('fecha', 'desc');
    }

    /**
     * Ordenar por fecha (más antiguos primero)
     * Uso: Blog::antiguo()->get()
     */
    public function scopeAntiguo($query)
    {
        return $query->orderBy('fecha', 'asc');
    }

    /**
     * Buscar por link o título en BlogHead
     * Uso: Blog::buscar('mi-blog')->get()
     */
    public function scopeBuscar($query, $termino)
    {
        if (!$termino) {
            return $query;
        }

        return $query->where('link', 'like', "%{$termino}%")
            ->orWhereHas('head', function ($q) use ($termino) {
                $q->where('titulo', 'like', "%{$termino}%");
            });
    }

    /**
     * Generar slug único para blog
     * Uso: $link = Blog::generarSlugUnico($titulo);
     */
    public static function generarSlugUnico($titulo)
    {
        $originalSlug = \Illuminate\Support\Str::slug($titulo);
        $link = $originalSlug;
        $counter = 1;

        while (Blog::where('link', $link)->exists()) {
            $link = $originalSlug . '-' . $counter++;
        }

        return $link;
    }


    public function scopeCompleto($query)
    {
        return $query->with([
            'head',
            'body',
            'footer',
            'card',
            'blogAuditoria.empleado',
        ]);
    }
}

