<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Log;

class PdfController extends Controller
{
    public function generatePdf($panels,$title = 'Grafana Dashboard',$from = 'now-6h', $to = 'now',$timezone = 'UTC'){
        $unitSize = 30;
        $page1Max = 800;
        $pageHeight = 990;
        $currentPage=1;
        $currentHeight=0;
        $previousRowYEnd = 0;
        // $panels= collect([
        //     ['x' =>0, 'y'=>0, 'w'=>4, 'h'=> 3, 'url'=>'images/10.png'],
        //     ['x' =>4, 'y'=>0, 'w'=>4, 'h'=> 3, 'url'=>'images/7.png'],
        //     ['x' =>8, 'y'=>0, 'w'=>4, 'h'=> 3, 'url'=>'images/11.png'],
        //     ['x' =>12, 'y'=>0, 'w'=>4, 'h'=> 3, 'url'=>'images/13.png'],
        //     ['x' =>16, 'y'=>0, 'w'=>4, 'h'=> 3, 'url'=>'images/12.png'],
        //     ['x' =>20, 'y'=>0, 'w'=>4, 'h'=> 3, 'url'=>'images/9.png'],
        //     ['x' =>0, 'y'=>3, 'w'=>3, 'h'=> 9, 'url'=>'images/6.png'],
        //     ['x' =>3, 'y'=>3, 'w'=>21, 'h'=> 9, 'url'=>'images/1.png'],
        //     ['x' =>0, 'y'=>12, 'w'=>3, 'h'=> 18, 'url'=>'images/15.png'],
        //     ['x' =>3, 'y'=>12, 'w'=>21, 'h'=> 8, 'url'=>'images/14.png'],
        //     ['x' =>3, 'y'=>20, 'w'=>21, 'h'=> 10, 'url'=>'images/16(2).png'],
        // ]);
        $panelsGroupedByY = $panels->groupBy('y');
        $grafanatime=new GrafanaTimeRange($from, $to,$timezone);
        $panelsWithPage = [];
        foreach ($panelsGroupedByY as $y => $group) {
            // Find max h in this row
            $maxH = $group->max('h');
            $rowHeight = $maxH * $unitSize;

            $maxPageHeight = ($currentPage === 1) ? $page1Max : $pageHeight;

            if ($currentHeight + $rowHeight > $maxPageHeight) {
                $currentPage++;
                $currentHeight = 0;
            }

            // Assign that page to all panels in this row
            foreach ($group as $panel) {
                $panel['page'] = $currentPage;
                $panel['imageUrl'] = $panel['url'];
                $panel['left'] = $panel['x'] * $unitSize;
                $panel['width'] = $panel['w'] * $unitSize;
                $panel['height'] = $panel['h'] * $unitSize;
                $panelsWithPage[] = $panel;
            }

            if($y >= $previousRowYEnd){
                $currentHeight += $rowHeight;
            }
            $previousRowYEnd = $y + $maxH;
        }
        $groupedByPage = collect($panelsWithPage)->groupBy('page');

        $finalPanels = [];

        //reset y for each page
        foreach($groupedByPage as $page => $panelsOnPage) {
            $minY = $panelsOnPage->min('y');

            foreach ($panelsOnPage as $panel) {
                $panel['y'] -= $minY;
                $panel['top'] = $panel['y'] * $unitSize;
                $finalPanels[] = $panel;
            }
        }

        $finalGrouped = collect($finalPanels)->groupBy('page');

        $pdf=Pdf::loadView('grafana', 
        [
            'groupedpanels' => $finalGrouped,
            'title'=> $title,
            'from'=>$grafanatime->fromFormatted(),
            'to'=>$grafanatime->toFormatted()
        ]);
        return $pdf->stream("$title.pdf");
    }

    public function getDashboadPanels(Request $request,$id){
        $url= parse_url(request()->getRequestUri());
        $validator = Validator::make($request->all(), [
            'apitoken' => 'required|string|min:10',
        ]);
        if ($validator->fails()) {
            return response()->json(["isSuccess"=>false,"data" => "Please provide the apitoken for the dashboard"], 400);
        }
        $apiKey = $request->input('apitoken') ?? '';
        $from = $request->input('from') ?? 'now-6h';
        $to = $request->input('to') ?? 'now';
        $vars=[];
        $query  = explode('&', $url['query']);
        $timezone='';
        if(isset($request->timezone) && !empty($request->timezone) && $request->timezone !== 'browser'){
            $timezone = $request->input('timezone');
        }else{
            $timezone = 'UTC';
        }
        $title = '';

        foreach($query as $param){
            if(str_starts_with($param, 'var-')){
                $vars[] = $param;
            }
        }
        $varQuery= implode('&', $vars);

        $headers=[
            "Accept"=>"application/json",
            "Content-Type"=>"application/json",
            "Authorization"=>"Bearer $apiKey"
        ];
        $GRAFANA_URL = env('GRAFANA_URL', 'http:://localhost:3000');
        $url="$GRAFANA_URL/api/dashboards/uid/$id?from=$from&to=$to";
        if(!empty($varQuery)){
            $url .= '&' . $varQuery;
        }
        $reponse=Http::withHeaders($headers)->get($url);
        if($reponse->successful()){
            $dashboard = $reponse->json()['dashboard'];
            $title=$dashboard['title'] ?? '';
            $panels = $dashboard['panels'] ?? [];

            $panelinfo = [];
            $htoRemove=0;
            if(!empty($panels)){
                foreach ($panels as $index=>$panel){
                    if(isset($panel['type']) && $panel['type'] === 'row' && !isset($panel['repeat'])){
                        // Skip row panels without repeat
                        $htoRemove += $panel['gridPos']['h'] ?? 0;
                        continue;
                    }
                    if(isset($panel['repeat'])){
                        if(isset($panel['repeatDirection'])){
                            $direction= $panel['repeatDirection'] ?? 'v';
                            $maxPerRow = $panel['maxPerRow'] ?? 1;
                            $repeat= $panel['repeat'] ?? '';
                            $newvars= [];
                            $othervars= [];
                            foreach($vars as $var){
                                $varName = explode('=', $var);
                                if(($varName[0] ?? '') === 'var-'.$repeat){
                                    $newvars[]=[$varName[0] ?? '' => $varName[1] ?? ''];
                                }
                                if(!str_starts_with($var,"var-$repeat")){
                                    $othervars[]=$var;
                                }
                            }
                            $othervarsquery= implode('&', $othervars);
                            foreach($newvars as $i=>$panelvar){
                                if($direction === 'v'){
                                    $varvalue='';
                                    foreach($panelvar as $key => $value){
                                        $varvalue .= "$key=$value";
                                    }
                                    if(!empty($othervars)){
                                        $varvalue .= '&' . $othervarsquery;
                                    }
                                    $panelinfo[]=[
                                        'id' => $panel['id'] ?? 1,
                                        'x' => $panel['gridPos']['x'] ?? 0,
                                        'y' => ($panel['gridPos']['y'] ?? 0) + ($panel['gridPos']['h'] ?? 0) * $i,
                                        'w' => $panel['gridPos']['w'] ?? 0,
                                        'h' => ($panel['gridPos']['h'] ?? 0)- $htoRemove,
                                        'from' => $from,
                                        'to' => $to,
                                        'var' => $varvalue
                                    ];
                                }else{
                                    $numberofCols=min($maxPerRow, count($newvars));
                                    $panelW = ($panel['gridPos']['w'] ?? 0) / $numberofCols;
                                    $panelH = ($panel['gridPos']['h'] ?? 0)- $htoRemove;
                                    $startX = $panel['gridPos']['x'] ?? 0;
                                    $startY = $panel['gridPos']['y'] ?? 0;
                                    $col=$i % $numberofCols;
                                    $row=floor($i / $numberofCols);
                                    $varvalue='';
                                    foreach($panelvar as $key => $value){
                                        $varvalue .= "$key=$value";
                                    }
                                    if(!empty($othervars)){
                                        $varvalue .= '&' . $othervarsquery;
                                    }
                                    $panelinfo[]=[
                                        'id' => $panel['id'] ?? 1,
                                        'x' => $startX + $panelW * $col,
                                        'y' => $startY + $panelH * $row,
                                        'w' => $panelW,
                                        'h' => $panelH,
                                        'from' => $from,
                                        'to' => $to,
                                        'var' => $varvalue
                                    ];
                                }
                            }
                        }else{
                            // If repeatDirection is not set, we assume it is a row repeat
                            $repeat= $panel['repeat'] ?? '';
                            $title = $panel['title'] ?? '';
                            $htoRemove += $panel['gridPos']['h'] ?? 0;
                            $rowpanels = (!empty($panel['panels'])) ? collect($panel['panels']) : collect($panels)->filter(function ($p) use ($repeat) {
                                return isset($p['title']) && $p['type'] !== 'row' && stripos($p['title'], $repeat) !== false;
                            })->values();
                            $newvars= [];
                            $othervars= [];
                            foreach($vars as $var){
                                $varName = explode('=', $var);
                                if(($varName[0] ?? '') === 'var-'.$repeat){
                                    $newvars[]=[$varName[0] ?? '' => $varName[1] ?? ''];
                                }
                                if(!str_starts_with($var,"var-$repeat")){
                                    $othervars[]=$var;
                                }
                            }
                            Log::info("Row repeat: $rowpanels");
                            return response()->json([
                                'isSuccess' => false,
                                'message' => $newvars,
                            ], 400);
                        }    
                    }else{
                        $othervarsquery= implode('&', $vars);
                        $panelinfo[]=[
                            'id' => $panel['id'] ?? 1,
                            'x' => $panel['gridPos']['x'] ?? 0,
                            'y' => $panel['gridPos']['y'] ?? 0,
                            'w' => $panel['gridPos']['w'] ?? 0,
                            'h' => ($panel['gridPos']['h'] ?? 0)- $htoRemove,
                            'from' => $from,
                            'to' => $to,
                            'var' => $othervarsquery
                        ];
                    }
                }
            }

            return $this->getEachPanel($panelinfo,$headers,$id,$title, $from, $to,$timezone);
        }else{
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch dashboard panels',
                'error' => $reponse->json()
            ], $reponse->status());
        }
    }

    public function getEachPanel($panelinfo,$headers,$id,$title = 'Grafana Dashboard',$from = 'now-6h', $to = 'now',$timezone = 'UTC'){
        $chunkSize = 2; // max number of concurrent requests
        $panelChunks = array_chunk($panelinfo, $chunkSize);
        $panels=[];
        foreach ($panelChunks as $chunk) {
            $responses=Http::pool(function (Pool $pool) use($chunk,$headers,$id){
                foreach($chunk as $panel){
                    $from=$panel['from'];
                    $to=$panel['to'];
                    $panelId=$panel['id'];
                    $height = ($panel['h'] ?? 0) * 40;
                    $width = ($panel['w'] ?? 0) * 40;
                    $url="https://monitoring.liquidtelecom.co.ke/render/d-solo/$id/_?from=$from&to=$to&panelId=$panelId&theme=light&height=$height&width=$width";
                    if(isset($panel['var']) && !empty($panel['var'])){
                        $url .= '&' . $panel['var'];
                    }

                    try{
                        $pool->withHeaders($headers)->get($url);
                    }catch(Exception $e){
                        return response()->json([
                            'isSuccess' => false,
                            'message' => 'Failed to fetch panel image',
                            'error' => $e->getMessage()
                        ], 500);
                    }
                }
            });

            foreach($responses as $index =>$response){
                try{
                    if($response->successful()){
                        $panel=$chunk[$index];
                        $time=time();
                        $filename = "panel_{$panel['id']}_{$panel['x']}_{$panel['y']}_{$time}.png";
                        $filepath= public_path("images/$filename");
                        file_put_contents($filepath, $response->body());
                        $panels[]=[
                            'x' => $panel['x'],
                            'y' => $panel['y'],
                            'w' => $panel['w'],
                            'h' => $panel['h'],
                            'url' => $filepath
                        ];
                    }else{
                        return response()->json([
                            'isSuccess' => false,
                            'message' => 'Failed to fetch panel image',
                            'error' => $response->json()
                        ], $response->status());
                    }
                }catch(Exception $e){
                    return response()->json([
                        'isSuccess' => false,
                        'message' => 'Failed to fetch panel image',
                        'error' => $e->getMessage()
                    ], 500);
                }
            }

            usleep(500000); // Sleep for 500 milliseconds to avoid overwhelming the server
        }

        return $this->generatePdf(collect($panels),$title, $from, $to,$timezone);
    }
}


class GrafanaTimeRange
{
    public $from;
    public $to;

    public $timezone;

    const REL_TIME_REGEX = '/^now([+-]\d+)([mhdwMy])$/';
    const BOUNDARY_TIME_REGEX = '/^(.*)\/([dwMy])$/';

    public function __construct($from = '', $to = '',$timezone = '')
    {
        $this->from = $from ?: 'now-1h';
        $this->to = $to ?: 'now';
        $this->timezone = $timezone ?: config('app.timezone');
    }

    public function fromFormatted()
    {
        return $this->parseBoundary($this->from, 'from')->toDayDateTimeString();
    }

    public function toFormatted()
    {
        return $this->parseBoundary($this->to, 'to')->toDayDateTimeString();
    }

    protected function parseBoundary($str, $boundary)
    {
        if ($this->isHumanFriendlyBoundary($str)) {
            [$moment, $unit] = $this->parseMomentAndBoundaryUnit($str);
            return $this->roundMomentToBoundary($moment, $boundary, $unit);
        }
        return $this->parseMoment($str);
    }

    protected function parseMomentAndBoundaryUnit($str)
    {
        if (!preg_match(self::BOUNDARY_TIME_REGEX, $str, $matches)) {
            throw new Exception("$str is not a recognised time format");
        }
        return [$this->parseMoment($matches[1]), $matches[2]];
    }

    protected function roundMomentToBoundary(Carbon $moment, $boundary, $unit)
    {
        switch ($unit) {
            case 'd':
                return $boundary === 'to'
                    ? $moment->copy()->endOfDay()
                    : $moment->copy()->startOfDay();
            case 'w':
                return $boundary === 'to'
                    ? $moment->copy()->endOfWeek()
                    : $moment->copy()->startOfWeek();
            case 'M':
                return $boundary === 'to'
                    ? $moment->copy()->endOfMonth()
                    : $moment->copy()->startOfMonth();
            case 'y':
                return $boundary === 'to'
                    ? $moment->copy()->endOfYear()
                    : $moment->copy()->startOfYear();
            default:
                return $moment;
        }
    }

    protected function parseMoment($str)
    {
        $localTz = $this->timezone ?: config('app.timezone');
        if ($str === 'now' || $str === '') {
            return Carbon::now($localTz);
        }
        if ($this->isRelativeTime($str)) {
            return $this->parseRelativeTime($str)->tz($localTz);
        }
        // Support ISO8601 (e.g., 2025-06-04T21:00:00.000Z)
        if ($this->isISO8601($str)) {
            try {
                return Carbon::parse($str)->tz($localTz);
            } catch (Exception $e) {
                throw new Exception("$str is not a recognised ISO8601 time format");
            }
        }
        // Assume absolute Unix time (ms or s)
        if (is_numeric($str)) {
            // If it's in ms, convert to seconds
            if (strlen($str) > 10) {
                $str = substr($str, 0, 10);
            }
            return Carbon::createFromTimestamp($str,'UTC')->tz($localTz);
        }
        throw new Exception("$str is not a recognised time format");
    }

    protected function parseRelativeTime($str)
    {
        if (!preg_match(self::REL_TIME_REGEX, $str, $matches)) {
            throw new Exception("$str is not a recognised relative time");
        }
        $number = intval($matches[1]);
        $unit = $matches[2];
        $now = Carbon::now();

        switch ($unit) {
            case 'm':
                return $now->addMinutes($number);
            case 'h':
                return $now->addHours($number);
            case 'd':
                return $now->addDays($number);
            case 'w':
                return $now->addWeeks($number);
            case 'M':
                return $now->addMonths($number);
            case 'y':
                return $now->addYears($number);
            default:
                return $now;
        }
    }

    protected function isRelativeTime($str)
    {
        return preg_match(self::REL_TIME_REGEX, $str);
    }

    protected function isHumanFriendlyBoundary($str)
    {
        return preg_match(self::BOUNDARY_TIME_REGEX, $str);
    }

    // NEW: Check if string is ISO8601
    protected function isISO8601($str)
    {
        // Simple ISO8601 detection (accepts 'Z' or timezone offset)
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(Z|[\+\-]\d{2}:\d{2})$/', $str);
    }
}


