<?php
namespace App\Http\Resources\Academy;
use Illuminate\Http\Resources\Json\JsonResource;
final class TranscriptResource extends JsonResource
{
    public function toArray($request): array { $r=(array)$this->resource; unset($r['person_id'],$r['curriculum_id'],$r['transcript_id']); if(is_array($r['lines']??null)){foreach($r['lines'] as &$line){unset($line['enrollment_id'],$line['certificate_id']);foreach(($line['grades']??[]) as &$grade){unset($grade['assessment_id']);}}} return $r; }
}
