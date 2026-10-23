<?php

namespace App\DTOs\WhatsAppCampaign;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class CreateCampaignDTO
{
    public function __construct(
        public readonly string $service,
        public readonly string $paragraph,
        public readonly UploadedFile $image,
        public readonly ?int $cantidadDestinatarios = null
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            service: $request->input('service'),
            paragraph: $request->input('paragraph'),
            image: $request->file('image'),
            cantidadDestinatarios: $request->filled('cantidad_destinatarios')
                ? (int) $request->input('cantidad_destinatarios')
                : null
        );
    }
}
