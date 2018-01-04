<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;

class TwitterService
{
    private   $container;
    protected $connect;
    protected $db;
    protected $log;

    protected $partyCode;
    protected $twUsername;
    protected $scrapeFull;
    protected $tw;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->log       = $this->container->get('logger');
        @set_exception_handler(array($this->connect, 'exception_handler'));
    }


    /**
     * Queries Twitter for stats and tweets
     * @param  string $partyCode
     * @param  string $twUsername
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getTwitterData($partyCode, $twUsername, $scrapeData = false, $scrapeFull = false) {
        $this->scrapeFull = $scrapeFull;
        $this->partyCode  = $partyCode;
        $this->twUsername = $twUsername;
        $this->tw         = $this->connect->getNewTwitter();

        $data = $this->connect->getTwRequest($this->tw, $twUsername);

        if (!isset($data->statuses_count)) {
            $this->log->notice("   - Twitter data not found for " . $this->partyCode);
            return false;
        }

        $out = $this->getTwStats($data);
        if (!empty($out)) {
            $this->log->info("    + Info and stats... ok");
            $this->log->info("      + Total " . $out['tweets'] . " tweets found");
        }

        if ($scrapeData == 'info') {
            return $out;
        }

        $temp = $this->getTweets();
        $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : 0;
        $out['images'] = isset($temp['images']) ? $temp['images'] : 0;
        $out['videos'] = isset($temp['videos']) ? $temp['videos'] : 0;

        $timeCheck = $temp['timeCheck'];
        $this->log->info("      + " . $out['posts'] . " text posts, " . $out['images'] . " images and " . $out['videos'] . " videos since " . date('d/m/Y', $timeCheck) . " processed");
 
        return $out;
    }


    /**
     * Retrieves Twitter account statistics
     * @param  object $data
     * @return array
     */
    public function getTwStats($data) {
        $array = [];

        if (isset($data->description)) {
            $this->db->addMeta(
                $this->partyCode,
                Metadata::TYPE_TWITTER_INFO,
                $data->description
            );
            $array['description'] = true;
        }

        if (isset($data->favourites_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_LIKES,
                $data->favourites_count
            );
            $array['likes'] = true;
        }

        if (isset($data->followers_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_FOLLOWERS,
                $data->followers_count
            );
            $array['followers'] = true;
        }

        if (isset($data->friends_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_FOLLOWING,
                $data->friends_count
            );
            $array['following'] = true;
        }

        if (isset($data->statuses_count)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_TWITTER,
                Statistic::SUBTYPE_POSTS,
                $data->statuses_count
            );
            $array['tweets'] = $data->statuses_count;
        }

        return $array;
    }

    /**
     * Processes tweets
     * @return array
     */
    public function getTweets() {
        $tweetData = $this->connect->getTwRequest($this->tw, $this->twUsername, true);

        if (empty($tweetData)) {
            $this->log->notice("    - Tweet details not found for " . $this->partyCode);
            return false;
        }

        $this->log->info("    + Getting tweet details...");
        $timeLimit = $this->db->getTimeLimit($this->partyCode, 'tw', 'T', $this->scrapeFull);

        $pageCount = 0;
        $txtCount  = 0;
        $imgCount  = 0;
        $vidCount  = 0;
        $loopCount = 0;
        $temp      = [];

        do { // process current page of results
            $this->log->debug("       + Page " . $pageCount);

            foreach($tweetData as $item) {
                if (in_array($item->id, $temp, true)) {
                    // if tweet was already scraped this session
                    $loopCount++;
                    continue;
                }
                $temp[] = $item->id;

                $twTime = \DateTime::createFromFormat('D M d H:i:s P Y', $item->created_at);
                // original string e.g. 'Mon Sep 08 15:19:11 +0000 2014'

                $tweet = $this->getTweetDetails($item, $twTime);

                if ($tweet === 'txt') {
                    $txtCount++;
                } else {
                    $imgCount += $tweet['img'];
                    $vidCount += $tweet['vid'];
                }
            }

            $timeCheck = $twTime->getTimestamp(); // check time of last tweet scraped
            $this->connect->getTwRateLimit($this->tw);

            // make new request to get next page of results
            $tweetData = $this->connect->getTwRequest($this->tw, $this->twUsername, true, $item->id);
            $pageCount++;

        } while ($timeCheck > $timeLimit && $pageCount < 100);
        // while tweet times are more recent than the limit as set above, up to 5000

        if ($loopCount > 0) {
            $this->log->warning("     - Tweet scraping for " . $this->partyCode . " looped " . $loopCount . " times");
        }

        $out['posts']     = $txtCount;
        $out['images']    = $imgCount;
        $out['videos']    = $vidCount;
        $out['timeCheck'] = $timeCheck;
        return $out;
    }


    /**
     * Retrieves the details of a tweet
     * @param  object $item
     * @param  object $twTime
     * @return string
     */
    public function getTweetDetails($item, $twTime) {
        if (!empty($item->full_text)) {
            $twText = $item->full_text;
        } else if (!empty($item->text)) {
            $twText = $item->text;
        } else $twText = null;

        if (!empty($item->entities->media)) { // if tweet contains media
            $tweet = $this->getMediaDetails($item, $twTime, $twText);
            return $tweet;
        }

        $allData = [
            'id'       => $item->id,
            'posted'   => $twTime->format('Y-m-d H:i:s'), // string
            'text'     => $twText,
            'url'      => 'https://twitter.com/statuses/' . $item->id,
            'likes'    => $item->favorite_count,
            'retweets' => $item->retweet_count
            ];

        $this->db->addSocial(
            $this->partyCode,
            SocialMedia::TYPE_TWITTER,
            SocialMedia::SUBTYPE_TEXT,
            $item->id,
            $twTime, // DateTime
            $twText,
            null,
            $item->favorite_count,
            $allData
        );

        return 'txt';
    }


    /**
     * Retrieves the details of an image or video
     * @param  object $item
     * @param  object $twTime
     * @param  string $twText
     * @return array
     */
    public function getMediaDetails($item, $twTime, $twText) {
        $media = $item->extended_entities->media;

        $imgCount = 0;
        $vidCount = 0;

        foreach ($media as $photo) { // if tweet contains multiple images
            if ($photo->type == 'video') {
                $subType = SocialMedia::SUBTYPE_VIDEO;
                $vidCount++;
            } else { // if type == 'photo' or 'animated_gif'
                $subType = SocialMedia::SUBTYPE_IMAGE;
                $imgCount++;
            }

            $imgSrc = $photo->media_url . ":small";
            $imgId  = $photo->id;
            $img    = $this->container
                ->get('ImageService')
                ->saveImage('tw', $this->partyCode, $imgSrc, $imgId);

            $allData = [
                'id'         => $imgId,
                'posted'     => $twTime->format('Y-m-d H:i:s'), // string
                'text'       => $twText,
                'image'      => $img,
                'img_source' => $imgSrc,
                'url'        => 'https://twitter.com/statuses/' . $item->id,
                'likes'      => $item->favorite_count,
                'retweets'   => $item->retweet_count
                ];

            $this->db->addSocial(
                $this->partyCode,
                SocialMedia::TYPE_TWITTER,
                $subType,
                $item->id,
                $twTime, // DateTime
                $twText,
                $img,
                $item->favorite_count,
                $allData
            );
        }

        $count['img'] = $imgCount;
        $count['vid'] = $vidCount;
        return $count;
    }

}
