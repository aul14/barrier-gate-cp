<?php

namespace App\Http\Controllers;

use App\Models\RealBarier;
use App\Models\TrackStatus;
use Illuminate\Http\Request;
use App\Models\LogBarierGate;
use Illuminate\Support\Facades\Http;

class ApiTimbanganController extends Controller
{
    public function getBearier1(Request $request)
    {
        try {
            $action = !empty($request->action) ? $request->action : "";
            $endpoint = env('API_URL');

            if ($request->from) {
                try {
                    if ($request->tipe == 'close') {
                        $response = Http::get("{$endpoint}?action_close={$action}");
                    } else {
                        $response = Http::get("{$endpoint}?Action={$action}");
                    }

                    $data = $response->body();

                    return $data;
                } finally {
                    $name = auth()->user()->fullname;
                    $log = new LogBarierGate();
                    $log->plant = "-";
                    $log->sequence = "-";
                    $log->arrival_date = date('Y-m-d');
                    $log->date_at = date('Y-m-d');
                    if ($request->tipe == 'close') {
                        $log->status = "close gate {$action} - by Dashboard Web | {$name}";
                        $log->next_status = "close gate {$action} - by Dashboard Web | {$name}";
                    } else {
                        $log->status = "open gate {$action} - by Dashboard Web | {$name}";
                        $log->next_status = "open gate {$action} - by Dashboard Web | {$name}";
                    }
                    $log->code_bg = 0;
                    $log->save();
                }
            } else {
                // OPEN OR CLOSE FROM SAP
                if ($request->tipe == 'close') {
                    $response = Http::get("{$endpoint}?action_close={$action}");
                } else {
                    $response = Http::get("{$endpoint}?Action={$action}");
                }

                $data = $response->body();

                return $data;
            }
        } catch (\Throwable $th) {
            return $th->getMessage();
        }
    }

    public function get_barrier_new(Request $request)
    {
        try {
            $api_url = env('API_URL');
            $api_external = env('API_EXTERNAL_SAP');

            $from = $request->from;
            $wb = $request->wb;
            /* type truck = short (gandeng = 1),
            type truck = long (manuver = 1),
            type truck = normal (manuver = 0, gandeng = 0) */
            $wb_condition = $request->wb_condition;
            $open_gate = $request->open_gate;
            $close_gate = $request->close_gate;

            $plant = $request->plant;
            $sequence = $request->sequence;
            $arrival_date = $request->arrival_date;

            if ($from) {
                try {
                    $response = Http::post("{$api_url}?WB={$wb}", [
                        "open" => $open_gate,
                        "close" => $close_gate,
                        "gandeng" => ($wb_condition == "short") ? 1 : 0,
                        "manuver" => ($wb_condition == "long") ? 1 : 0
                    ]);

                    $data = $response->body();

                    // cek status api
                    $reqStatus = Http::post("{$api_url}/Status", [
                        "WB" => (int)$wb
                    ]);

                    $dataStatus = $reqStatus->json();
                    // end cek status api

                    // send api external WA GATEWAY
                    if (env('APP_NAME') == 'BARRIER_GATE_BALAJARA_PRODUCTION' || env('APP_NAME') == 'BARRIER_GATE_BALAJARA_DEVELOPMENT') {
                        $gateIn = $dataStatus['GateIn'];
                        $gateOut = $dataStatus['GateOut'];

                        if ($gateIn == 'Open') {
                            $msgGate = "Manual Open Gate In";
                        } else if ($gateOut == 'Open') {
                            $msgGate = "Manual Open Gate Out";
                        }

                        if ($wb == 1) {
                            $msgTs = "Truck Scale 1";
                        } else if ($wb == 2) {
                            $msgTs = "Truck Scale 2";
                        } else if ($wb == 3) {
                            $msgTs = "Truck Scale 3";
                        } else if ($wb == 4) {
                            $msgTs = "Truck Scale 4";
                        }

                        date_default_timezone_set('Asia/Jakarta');
                        $now = new \DateTime();
                        $formattedDate = $now->format('d-m-Y H:i:s');

                        $dateNow = $formattedDate;
                        $fullname = auth()->user()->fullname;
                        $reqExternal = Http::post("{$api_external}/TsGateManual", [
                            "activity" => "manual open gate",
                            "msg" => "{$msgGate}, {$msgTs}",
                            "Gate In" => $gateIn,
                            "Gate Out" => $gateOut,
                            "Operator" => $fullname,
                            "Open gate time" => $dateNow
                        ]);

                        $reqExternal->json();
                    }
                    // end send api external WA GATEWAY

                    return $data;
                } finally {
                    $name = auth()->user()->fullname;

                    if ($plant && $sequence && $arrival_date) {
                        if ($plant == '0' && $sequence == '0') {
                            $log = new LogBarierGate();
                            $log->plant = "-";
                            $log->sequence = "-";
                            $log->arrival_date = date('Y-m-d');
                            $log->date_at = date('Y-m-d');
                            if ($close_gate) {
                                $log->status = "WB {$wb}, close gate {$close_gate} - by Dashboard Web | {$name}";
                                $log->next_status = "WB {$wb}, close gate {$close_gate} - by Dashboard Web | {$name}";
                            } else {
                                $log->status = "WB {$wb}, open gate {$open_gate} - by Dashboard Web | {$name}";
                                $log->next_status = " WB {$wb}, open gate {$open_gate} - by Dashboard Web | {$name}";
                            }
                            $log->code_bg = 0;
                            $log->save();
                        } else {
                            if ($close_gate) {
                                $status = "WB {$wb}, close gate {$close_gate} - by Dashboard Web | {$name}";
                            } else {
                                $status = "WB {$wb}, open gate {$open_gate} - by Dashboard Web | {$name}";
                            }

                            $ts_old = TrackStatus::where('plant', $plant)->where('sequence', $sequence)->where('arrival_date', $arrival_date)->orderBy('id', 'DESC')->first();

                            if ($ts_old) {
                                $status_ts_old = $ts_old->status;

                                $ts_old->delete();

                                $ts = new TrackStatus();
                                $ts_arr = [
                                    [
                                        'plant' => $plant,
                                        'sequence' => $sequence,
                                        'arrival_date' => $arrival_date,
                                        'status' => $status,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ],
                                    [
                                        'plant' => $plant,
                                        'sequence' => $sequence,
                                        'arrival_date' => $arrival_date,
                                        'status' => $status_ts_old,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]
                                ];
                                $ts->insert($ts_arr);
                            }

                            $cek_rb = RealBarier::where('plant', $plant)->where('sequence', $sequence)->where('arrival_date', $arrival_date)->first();

                            $cek_rb->updated_at = now();
                            $cek_rb->update();

                            Http::post("{$api_url}/real-time", [
                                'status'  => $status,
                                'wb'  => $wb,
                                'open_gate'  => $open_gate,
                                'wb_condition'  => $wb_condition,
                                'direction'  => null,
                                'truck_no' => !empty($cek_rb->truck_no) ? $cek_rb->truck_no : '',
                                'from' => $from,
                                'antrian' => false,
                                'data_antrian' => []
                            ]);

                            $log = new LogBarierGate();
                            $log->plant = $plant;
                            $log->sequence = $sequence;
                            $log->arrival_date = $arrival_date;
                            $log->date_at = date('Y-m-d');
                            if ($close_gate) {
                                $log->status = "WB {$wb}, close gate {$close_gate} - by Dashboard Web | {$name}";
                                $log->next_status = "WB {$wb}, close gate {$close_gate} - by Dashboard Web | {$name}";
                            } else {
                                $log->status = "WB {$wb}, open gate {$open_gate} - by Dashboard Web | {$name}";
                                $log->next_status = " WB {$wb}, open gate {$open_gate} - by Dashboard Web | {$name}";
                            }
                            $log->code_bg = 0;
                            $log->save();
                        }
                    } else {
                        $log = new LogBarierGate();
                        $log->plant = "-";
                        $log->sequence = "-";
                        $log->arrival_date = date('Y-m-d');
                        $log->date_at = date('Y-m-d');
                        if ($close_gate) {
                            $log->status = "WB {$wb}, close gate {$close_gate} - by Dashboard Web | {$name}";
                            $log->next_status = "WB {$wb}, close gate {$close_gate} - by Dashboard Web | {$name}";
                        } else {
                            $log->status = "WB {$wb}, open gate {$open_gate} - by Dashboard Web | {$name}";
                            $log->next_status = " WB {$wb}, open gate {$open_gate} - by Dashboard Web | {$name}";
                        }
                        $log->code_bg = 0;
                        $log->save();
                    }
                }
            } else {
                // OPEN OR CLOSE FROM SAP
                try {
                    $response = Http::post("{$api_url}?WB={$wb}", [
                        "open" => $open_gate,
                        "close" => $close_gate,
                        "gandeng" => ($wb_condition == "short") ? 1 : 0,
                        "manuver" => ($wb_condition == "long") ? 1 : 0
                    ]);

                    $data = $response->body();

                    $maxTry = 3;
                    $attempt = 0;
                    $gateIn = null;
                    $gateOut = null;

                    do {
                        $reqStatus = Http::post("{$api_url}/Status", [
                            "WB" => (int)$wb
                        ]);

                        $dataStatus = $reqStatus->json();
                        $gateIn     = $dataStatus['GateIn'] ?? null;
                        $gateOut    = $dataStatus['GateOut'] ?? null;

                        $attempt++;

                        if ($gateIn === 'Open' || $gateOut === 'Open') {
                            return $data;
                        }

                        sleep(1);
                    } while ($attempt < $maxTry);

                    return response()->json([
                        "status"  => "error",
                        "message" => "Gate tidak terbuka setelah 3 kali percobaan"
                    ]);
                } finally {
                    $name = auth()->user()->fullname;
                    $log = new LogBarierGate();
                    $log->plant = $plant;
                    $log->sequence = $sequence;
                    $log->arrival_date = $arrival_date;
                    $log->date_at = date('Y-m-d');
                    if ($close_gate) {
                        $log->status = "WB {$wb}, close gate {$close_gate} - by SAP";
                        $log->next_status = "WB {$wb}, close gate {$close_gate} - by SAP";
                    } else {
                        $log->status = "WB {$wb}, open gate {$open_gate} - by SAP";
                        $log->next_status = " WB {$wb}, open gate {$open_gate} - by SAP";
                    }
                    $log->code_bg = 0;
                    $log->save();
                }
            }
        } catch (\Throwable $th) {
            return $th->getMessage();
        }
    }
}
