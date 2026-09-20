<?php
namespace App\Http\Resources\Academy;
use Illuminate\Http\Resources\Json\JsonResource;
final class CertificateResource extends JsonResource
{
    public function toArray($request): array { $r=(array)$this->resource; return ['public_id'=>$r['public_id'],'version'=>(int)$r['version'],'token'=>$r['token']]; }
}
