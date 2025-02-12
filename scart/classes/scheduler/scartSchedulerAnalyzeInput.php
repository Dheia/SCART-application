<?php
namespace abuseio\scart\classes\scheduler;

use abuseio\scart\models\Input_parent;
use abuseio\scart\models\Scrape_cache;
use Config;
use abuseio\scart\models\Addon;
use abuseio\scart\models\Input;
use abuseio\scart\Controllers\Startpage;
use abuseio\scart\models\Systemconfig;
use abuseio\scart\classes\helpers\scartLog;
use abuseio\scart\classes\online\scartAnalyzeInput;
use abuseio\scart\classes\mail\scartAlerts;

class scartSchedulerAnalyzeInput extends scartScheduler {

    /**
     * Schedule AnalyzeInput
     *
     * -1-
     * Select (mainurl) inputs with status scheduler_scrape
     *   a) Analyze and get images/whois
     *   b) If to-be-classified (grade) and AI analyzer active, then call (notify) AI analyzer
     * Notify scheduler operator
     *
     * -2-
     * check if AI analyzer is active (eg AI)
     * If so, check if AI analyzing is ready -> if ready, then process result
     *
     *
     * @return int
     */
    public static function doJob() {

        $cnt = 0;

        if (SELF::startScheduler('AnalyseInput','scrape')) {

            /** SCHEDULED INPUT(S) **/

            // login okay
            $job_inputs = array();

            // load addon (if active)
            $AIaddon = Addon::getAddonType(SCART_ADDON_TYPE_AI_IMAGE_ANALYZER);

            // Each scheduler time process available records within the scheduler_process_minutes

            $scheduler_process_count = Systemconfig::get('abuseio.scart::scheduler.scrape.scheduler_process_count','');
            if ($scheduler_process_count=='') $scheduler_process_count = Systemconfig::get('abuseio.scart::scheduler.scheduler_process_count',15);

            $scheduler_process_minutes = Systemconfig::get('reportertool.eokm::scheduler.scrape.scheduler_process_minutes','');
            if ($scheduler_process_minutes=='') $scheduler_process_minutes = 5;

            // STEP-1 Check if waiting for AI analyze results

            if ($AIaddon) {

                /**
                 * After the scrape&whois step (see below step 2) the records are set send to the AI module and set on ANALYZE_AI status (if image data)
                 *
                 * In this step we poll the AI module for the results of the AI analyze
                 * If we have the results of all image from a mainurl, when set mainurl on classify (grade)
                 *
                 */

                $count = Input::where('status_code',SCART_STATUS_SCHEDULER_AI_ANALYZE)
                    ->count();
                scartLog::logLine("D-scheduleAnalyseInput; process minutes=$scheduler_process_minutes; total records to check AI analyzer: $count");

                $maxmins = $scheduler_process_minutes * 60;  // get seconds
                $curtime = microtime(true);
                $endtime = $curtime + $maxmins;

                // find all input(s) with status=AI_ANALYZE
                $input = Input::where('status_code',SCART_STATUS_SCHEDULER_AI_ANALYZE)
                    ->where('url_type',SCART_URL_TYPE_MAINURL)
                    ->orderBy('id','ASC')
                    ->first();
                while ($input && ($curtime <= $endtime)) {

                    // select all records (WITHOUT mainurl) with waiting status
                    $records = Input::join(SCART_INPUT_PARENT_TABLE, SCART_INPUT_PARENT_TABLE.'.input_id', '=', SCART_INPUT_TABLE.'.id')
                        ->where(SCART_INPUT_PARENT_TABLE.'.deleted_at',null)
                        ->where(SCART_INPUT_PARENT_TABLE.'.parent_id', $input->id)
                        ->where(SCART_INPUT_TABLE.'.id','<>',$input->id)
                        ->where(SCART_INPUT_TABLE.'.url_type', '<>', SCART_URL_TYPE_VIDEOURL)
                        ->where(SCART_INPUT_TABLE.'.status_code', SCART_STATUS_SCHEDULER_AI_ANALYZE)
                        ->select(SCART_INPUT_TABLE.'.*')
                        ->get();

                    if (count($records) > 0) {

                        scartLog::logLine("D-ScheduleAnalyseInput; $input->filenumber; images waiting for AI analyze: ".count($records));

                        $done = true;
                        foreach ($records AS $record) {
                            $done = self::pollAI($record,$AIaddon,$done);
                        }

                    } else {

                        $done = true;

                    }

                    if ($done) {

                        scartLog::logLine("D-ScheduleAnalyseInput; $input->filenumber; images AI analyzed ");

                        $done = self::pollAI($input,$AIaddon,$done);

                        if ($done) {

                            $logtimestamp = '['.date('Y-m-d H:i:s').'] ';

                            // fill job report
                            $job_inputs[$input->filenumber] = [
                                'filenumber' => $input->filenumber,
                                'url' => $input->url,
                                'status' => $input->status_code,
                                'notcnt' => $input->delivered_items,
                                'warning' =>  '',
                            ];

                        }

                    } else {

                        // @TO-DO; check if not waiting to long ->



                    }

                    // NEXT MAINURL record

                    $input = Input::where('status_code',SCART_STATUS_SCHEDULER_AI_ANALYZE)
                        ->where('url_type',SCART_URL_TYPE_MAINURL)
                        ->where('id','>',$input->id)
                        ->orderBy('id','ASC')
                        ->first();
                    $curtime = microtime(true);

                }

            }

            // STEP-2 Scrape inputs

            $count = Input::where('status_code',SCART_STATUS_SCHEDULER_SCRAPE)
                ->where('url_type',SCART_URL_TYPE_MAINURL)
                ->count();
            scartLog::logLine("D-scheduleAnalyseInput; process minutes=$scheduler_process_minutes; total records to analyze: $count");

            $maxmins = $scheduler_process_minutes * 60;  // get seconds
            $curtime = microtime(true);
            $endtime = $curtime + $maxmins;

            // find all input(s) with status=scheduled
            $input = Input::where('status_code',SCART_STATUS_SCHEDULER_SCRAPE)
                ->where('url_type',SCART_URL_TYPE_MAINURL)
                ->orderBy('received_at','DESC')
                ->first();
            while ($input && ($curtime <= $endtime)) {

                $cnt += 1;
                scartLog::logLine("D-scheduleAnalyseInput [$cnt]; filenumber=$input->filenumber; seconds still to go: " . ($endtime - $curtime) );

                // set working

                // log old/new for history
                $input->logHistory(SCART_INPUT_HISTORY_STATUS,$input->status_code,SCART_STATUS_WORKING,"Working on analyze (scrape)");

                $input->status_code = SCART_STATUS_WORKING;
                $input->save();

                $warning = '';
                $warning_timestamp = '['.date('Y-m-d H:i:s').'] ';

                try {

                    // do analyze
                    $result = scartAnalyzeInput::doAnalyze($input);

                    if ($result['status']) {
                        if ($input->status_code == SCART_STATUS_WORKING) {

                            // next fase
                            $status_next = ($AIaddon) ? SCART_STATUS_SCHEDULER_AI_ANALYZE : SCART_STATUS_GRADE;
                            // log old/new for history
                            $input->logHistory(SCART_INPUT_HISTORY_STATUS,$input->status_code,$status_next,"Analyze (scrape) done; next fase");
                            $input->status_code = $status_next;
                            $input->classify_status_code = SCART_STATUS_GRADE;
                            $warning = '';
                        } else {
                            // status_code already set on next step
                            $warning = $result['warning'];
                        }
                    } else {

                        // log old/new for history
                        $input->logHistory(SCART_INPUT_HISTORY_STATUS,$input->status_code,SCART_STATUS_CANNOT_SCRAPE,"Cannot scrape");

                        $input->status_code = SCART_STATUS_CANNOT_SCRAPE;
                        $warning = $result['warning'];
                    }
                    if ($warning) $input->logText('Cannot scrape reason: ' . $warning);
                    $input->save();
                    $input->logText("Set status_code on: " . $input->status_code);

                } catch (\Exception $err) {

                    scartLog::logLine("E-ScheduleAnalyseInput.doAnalyze exception on line " . $err->getLine() . " in " . $err->getFile() . "; message: " . $err->getMessage() );
                    $warning = 'error analyzing input - manual action needed';
                    $result = [
                        'status' => false,
                        'warning' => $warning,
                        'notcnt' => 0,
                    ];

                    // log old/new for history
                    $input->logHistory(SCART_INPUT_HISTORY_STATUS,$input->status_code,SCART_STATUS_CANNOT_SCRAPE,"Cannot scrape");

                    $input->status_code = SCART_STATUS_CANNOT_SCRAPE;
                    $input->save();
                }

                scartLog::logLine("D-scheduleAnalyseInput [$cnt]; filenumber=$input->filenumber, url=$input->url, received_at=$input->received_at, status=$input->status_code, warning=$warning ");

                // STEP-3; Check if AI analyze active and input set on classify (grade)

                if ($AIaddon && $input->status_code == SCART_STATUS_SCHEDULER_AI_ANALYZE) {

                    // get all records (WITH mainurl) and put into ai_analyze mode (if imagedata)
                    $records = Input::join(SCART_INPUT_PARENT_TABLE, SCART_INPUT_PARENT_TABLE.'.input_id', '=', SCART_INPUT_TABLE.'.id')
                        ->where(SCART_INPUT_PARENT_TABLE.'.deleted_at',null)
                        ->where(SCART_INPUT_PARENT_TABLE.'.parent_id', $input->id)
                        ->where(SCART_INPUT_TABLE.'.status_code', SCART_STATUS_GRADE)
                        ->where(SCART_INPUT_TABLE.'.url_type', '<>', SCART_URL_TYPE_VIDEOURL)
                        ->select(SCART_INPUT_TABLE.'.*')
                        ->get();

                    // @TO-DO; (question) skip screenshots... -> indicator field in input
                    // @TO-DO; (question) skip grade_code not-illegal and ignore?

                    scartLog::logLine("D-ScheduleAnalyseInput; push ".count($records)." to AI analyzer ");

                    $intoAIstatus = false;
                    foreach ($records AS $record) {

                        $image = Scrape_cache::where('code',$record->url_hash)->first();

                        if ($image) {

                            $data = explode(',', $image->cached);
                            $base64 = $data[1];
                            $parm = [
                                'action' => 'push',
                                'post' =>
                                    [
                                        'SCART_ID' => $record->filenumber,
                                        'image' => $base64,
                                    ],
                            ];
                            if (Addon::run($AIaddon,$parm)) {

                                $intoAIstatus = true;

                                // log old/new for history
                                $record->logHistory(SCART_INPUT_HISTORY_STATUS,$record->status_code,SCART_STATUS_SCHEDULER_AI_ANALYZE,"Wait for AI analyze");

                                $record->status_code = SCART_STATUS_SCHEDULER_AI_ANALYZE;
                                $record->save();

                                $record->logText("Pushed image to AI analyzer; filenumber=$record->filenumber");

                            } else {

                                // @TO-DO; what is timeout -> may be retry 3 times?

                                scartLog::logLine("W-ScheduleAnalyseInput; error adding image to AI Analyzer; filenumber=$record->filenumber; error: " . Addon::getLastError($AIaddon));
                            }

                        } else {

                            scartLog::logLine("W-ScheduleAnalyseInput; cannot find imagedata (cache); filenumber=$record->filenumber, hash=$record->url_hash");

                        }

                    }

                    if (!$intoAIstatus) {
                        // no image submitted -> set SCART_STATUS_GRADE
                        // log old/new for history
                        $input->logHistory(SCART_INPUT_HISTORY_STATUS,$input->status_code,SCART_STATUS_GRADE,"No push to AI module done");
                        $input->status_code = SCART_STATUS_GRADE;
                        $input->save();
                    }

                    // report new status
                    $job_inputs[$input->filenumber]['status'] = SCART_STATUS_SCHEDULER_AI_ANALYZE;

                }

                // fill job report
                $job_inputs[$input->filenumber] = [
                    'filenumber' => $input->filenumber,
                    'url' => $input->url,
                    'status' => $input->status_code,
                    'notcnt' => $result['notcnt'],
                    'warning' => (($warning) ? $warning_timestamp . $warning : $warning),
                ];

                // NEXT record

                $input = Input::where('status_code',SCART_STATUS_SCHEDULER_SCRAPE)
                    ->where('url_type',SCART_URL_TYPE_MAINURL)
                    ->orderBy('received_at','DESC')
                    ->first();
                $curtime = microtime(true);
            }


            // log/alert

            if (count($job_inputs) > 0) {

                $params = [
                    'job_inputs' => $job_inputs,
                ];
                scartAlerts::insertAlert(SCART_ALERT_LEVEL_INFO,'abuseio.scart::mail.scheduler_analyze_input', $params);

            } else {

                scartLog::logLine("D-scheduleAnalyseInput; no input records found");

            }

        }

        SELF::endScheduler();

        return $cnt;
    }

    /**
     * Poll AI module for analyze results
     * Return false if not yet done
     * Update record if done or error/timeout status
     *
     * @param $record
     * @param $AIaddon
     * @param $done
     * @return false|mixed
     */

    static function pollAI($record,$AIaddon,$done) {

        $parm = [
            'action' => 'poll',
            'post' => $record->filenumber,
        ];
        $result = Addon::run($AIaddon,$parm);

        if ($result) {

            foreach ($result AS $name => $value) {
                $record->addExtrafield( SCART_INPUT_EXTRAFIELD_PWCAI,$name,$value);
            }
            $logtext = "add AI analyze results (attributes); number of attributes=".count($result);
            scartLog::logLine("D-scheduleAnalyseInput; filenumber=$record->filenumber; $logtext");

            // log old/new for history
            $record->logHistory(SCART_INPUT_HISTORY_STATUS,$record->status_code,SCART_STATUS_GRADE,"Got AI analyze results");

            $record->status_code = SCART_STATUS_GRADE;
            $record->save();
            $record->logText($logtext);

        } else {

            $resulterror = Addon::getLastError($AIaddon);
            if ($resulterror) {

                $logtext = "error in AI analyze: $resulterror";
                scartLog::logLine("E-scheduleAnalyseInput; filenumber=$record->filenumber; $logtext");

                // log old/new for history
                $record->logHistory(SCART_INPUT_HISTORY_STATUS,$record->status_code,SCART_STATUS_GRADE,"Error in AI module; skip");

                // SKIP AI
                $record->status_code = SCART_STATUS_GRADE;
                $record->save();
                $record->logText($logtext);

            } else {

                $lasttime = strtotime($record->updated_at);

                // skip if timeout (2022/1/21; 4 hours)
                if (time() - $lasttime >= SCART_MAX_TIME_AI_ANALYZE) {

                    $logtext = "timeout waiting for AI module - skip  AI";
                    scartLog::logLine("E-scheduleAnalyseInput; filenumber=$record->filenumber; $logtext");

                    // log old/new for history
                    $record->logHistory(SCART_INPUT_HISTORY_STATUS,$record->status_code,SCART_STATUS_GRADE,ucfirst($logtext));

                    // go to classify
                    $record->status_code = SCART_STATUS_GRADE;
                    $record->save();
                    $record->logText($logtext);

                } else {
                    // not (yet) ready
                    $done = false;
                }

            }

        }

        return $done;
    }


}
