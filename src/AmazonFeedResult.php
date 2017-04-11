<?php
namespace VincentWon\Mws;

/**
 * Copyright 2013 CPI Group, LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 *
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Retrieves feeds from Nye.
 *
 * This Nye Feeds Core object can retrieve the results of a
 * processed feed from Nye, which can then be saved to a file
 * specified by the user or retrieved as a single string.
 * In order to fetch feed results, the feed's ID must be given.
 */
class AmazonFeedResult extends AmazonFeedsCore
{
    protected $rawFeed;
    protected $data;

    /**
     * AmazonFeedResult gets the result of a Feed from Nye.
     *
     * The parameters are passed to the parent constructor, which are
     * in turn passed to the AmazonCore constructor. See it for more information
     * on these parameters and common methods.
     * Please note that an extra parameter comes before the usual Mock Mode parameters,
     * so be careful when setting up the object.
     * @param string $s [optional] <p>Name for the store you want to use.
     * This parameter is optional if only one store is defined in the config file.</p>
     * @param string $id [optional] <p>The Feed Submission ID to set for the object.</p>
     * @param boolean $mock [optional] <p>This is a flag for enabling Mock Mode.
     * This defaults to <b>FALSE</b>.</p>
     * @param array|string $m [optional] <p>The files (or file) to use in Mock Mode.</p>
     * @param string $config [optional] <p>An alternate config file to set. Used for testing.</p>
     */
    public function __construct($s = null, $id = null, $mock = false, $m = null, $config = null)
    {
        parent::__construct($s, $mock, $m, $config);
        if ($id) {
            $this->options['FeedSubmissionId'] = $id;
        }
        $this->options['Action'] = 'GetFeedSubmissionResult';
        if (isset($this->env['THROTTLE_LIMIT_FEEDRESULT'])) {
            $this->throttleLimit = $this->env['THROTTLE_LIMIT_FEEDRESULT'];
        }
        if (isset($this->env['THROTTLE_TIME_FEEDRESULT'])) {
            $this->throttleTime = $this->env['THROTTLE_TIME_FEEDRESULT'];
        }
        $this->throttleGroup = 'GetFeedSubmissionResult';
    }

    /**
     * Sets the feed submission ID for the next request. (Required)
     *
     * This method sets the feed submission ID to be sent in the next request. This
     * parameter is required in order to retrieve a feed from Nye.
     * @param string|integer $n <p>Must be numeric</p>
     * @return boolean <b>FALSE</b> if improper input
     */
    public function setFeedId($n)
    {
        if (is_numeric($n)) {
            $this->options['FeedSubmissionId'] = $n;
        } else {
            return false;
        }
    }

    /**
     * Sends a request to Nye for a feed.
     *
     * Submits a <i>GetFeedSubmissionResult</i> request to Nye. In order to
     * do this, a feed submission ID is required. Nye will send back the raw results
     * of the feed as a response, which can be saved to a file using <i>saveFeed</i>.
     * @return boolean <b>FALSE</b> if something goes wrong
     */
    public function fetchFeedResult()
    {
        if (!array_key_exists('FeedSubmissionId', $this->options)) {
            $this->log("Feed Submission ID must be set in order to fetch it!", 'Warning');
            return false;
        }
        $url = $this->urlbase . $this->urlbranch;
        $query = $this->genQuery();
        if ($this->mockMode) {
            $this->rawFeed = $this->fetchMockFile(false);
        } else {
            $response = $this->sendRequest($url, array('Post' => $query));
            if (!$this->checkResponse($response)) {
                return false;
            }
            $this->rawFeed = $response['body'];
        }
    }

    protected function parseXML($xml)
    {
        if (!$xml) {
            return false;
        }
        if (!isset($xml->Message->ProcessingReport)) {
            return false;
        }
        $result = $xml->Message->ProcessingReport;
        $d = array();
        if (isset($result->ProcessingSummary)) {
            if ($result->ProcessingSummary->MessagesProcessed > 0 and $result->ProcessingSummary->MessagesSuccessful > 0) {
                $d['code'] = 'success';
                $d['messages'][] = 'Success.';
            }
            if ($result->ProcessingSummary->MessagesWithError > 0) {
                $d['code'] = 'error';
                if (isset($result->Result)) {
                    if (count($result->Result) > 1) {
                        for ($i = 0; $i < count($result->Result); $i++) {
                            $d['messages'][$i] = (string)$result->Result[$i]->ResultDescription;
                        }
                    } else {
                        $d['messages'][] = (string)$result->Result->ResultDescription;
                    }
                }
            }
            if ($result->ProcessingSummary->MessagesWithWarning > 0) {
                $d['code'] = 'warning';
                if (isset($result->Result)) {
                    if (count($result->Result) > 1) {
                        for ($i = 0; $i < count($result->Result); $i++) {
                            $d['messages'][$i] = (string)$result->Result[$i]->ResultDescription;
                        }
                    } else {
                        $d['messages'][] = (string)$result->Result->ResultDescription;
                    }
                }
            }
        }
        $this->data = $d;
    }

    /**
     * Saves the raw report data to a path you specify.
     *
     * This method will record in the log whether or not the save was successful.
     * @param string $path <p>path for the file to save the feed data in</p>
     * @return boolean <b>FALSE</b> if something goes wrong
     */
    public function saveFeed($path)
    {
        if (!isset($this->rawFeed)) {
            return false;
        }
        try {
            file_put_contents($path, $this->rawFeed);
            $this->log("Successfully saved feed #" . $this->options['FeedSubmissionId'] . " at $path");
        } catch (Exception $e) {
            $this->log("Unable to save feed #" . $this->options['FeedSubmissionId'] . " at $path: " . $e->getMessage(),
                'Urgent');
            return false;
        }
    }

    /**
     * Returns the entire raw report data.
     *
     * This is useful for handling the report with an external file management system.
     * @return string|boolean The raw report data as a string, or <b>FALSE</b> if there is no data
     */
    public function getRawFeed()
    {
        if (!isset($this->rawFeed)) {
            return false;
        }
        return $this->rawFeed;
    }

    public function getFeedResult()
    {
        if (!isset($this->rawFeed)) {
            return false;
        }
        if (!$this->rawFeed) {
            return false;
        }
        $xml = simplexml_load_string($this->rawFeed);
        $this->parseXML($xml);
        return $this->data;
    }
}