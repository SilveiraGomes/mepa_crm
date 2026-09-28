<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Territorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Territorial\TerritorialCreateRequest;
use App\Http\Requests\Territorial\TerritorialLifecycleRequest;
use App\Http\Requests\Territorial\TerritorialListRequest;
use App\Http\Requests\Territorial\TerritorialMoveRequest;
use App\Http\Requests\Territorial\TerritorialUpdateRequest;
use App\Http\Territorial\TerritorialOutput;
use App\Http\Territorial\TerritorialServiceFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TerritorialController extends Controller
{
    public function __construct(private TerritorialServiceFactory $factory) {}
    private function ids(Request $r):array{return [(int)$r->user()->getAuthIdentifier(),(int)$r->attributes->get('auth_session_id')];}
    public function context(Request $r):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::item($this->factory->make()->context($u,$s));}
    public function index(TerritorialListRequest $r):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::page($r,$this->factory->make()->list($u,$s,$r->validated()));}
    public function roots(TerritorialListRequest $r):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::page($r,$this->factory->make()->roots($u,$s,$r->validated()));}
    public function show(Request $r,string $unit):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::item($this->factory->make()->detail($u,$s,$unit));}
    public function children(TerritorialListRequest $r,string $unit):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::page($r,$this->factory->make()->children($u,$s,$unit,$r->validated()));}
    public function path(Request $r,string $unit):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::item($this->factory->make()->path($u,$s,$unit));}
    public function store(TerritorialCreateRequest $r):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::item($this->factory->make()->create($u,$s,$r->validated()),201);}
    public function update(TerritorialUpdateRequest $r,string $unit):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::item($this->factory->make()->update($u,$s,$unit,$r->validated()));}
    public function move(TerritorialMoveRequest $r,string $unit):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::item($this->factory->make()->move($u,$s,$unit,$r->validated()));}
    public function lifecycle(TerritorialLifecycleRequest $r,string $unit):JsonResponse{[$u,$s]=$this->ids($r);return TerritorialOutput::item($this->factory->make()->lifecycle($u,$s,$unit,$r->validated()));}
}
