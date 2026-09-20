<?php
namespace App\Http\Resources\Academy;
use Illuminate\Http\Resources\Json\JsonResource;
final class EnrollmentResource extends JsonResource
{
    public function toArray($request): array { $r=(array)$this->resource; return array_filter(['public_id'=>$r['public_id']??null,'outcome'=>$r['outcome']??null,'from'=>$r['from']??null,'to'=>$r['to']??null,'lock_version'=>$r['lock_version']??null], static fn($v)=>$v!==null); }
}
