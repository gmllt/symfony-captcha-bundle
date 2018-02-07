<?php

namespace Captcha\Bundle\CaptchaBundle\Controller;

use Captcha\Bundle\CaptchaBundle\Helpers\BotDetectCaptchaHelper;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class CaptchaHandlerController extends Controller
{
    /**
     * @var object
     */
    private $captcha;

    /**
     * Handle request from querystring such as getting image, getting sound, etc.
     */
    public function indexAction()
    {
        $this->captcha = $this->getBotDetectCaptchaInstance();

        if (is_null($this->captcha)) {
            throw new BadRequestHttpException('captcha');
        }

        $commandString = $this->getUrlParameter('get');
        if (!\BDC_StringHelper::HasValue($commandString)) {
            \BDC_HttpHelper::BadRequest('command');
        }

        $command = \BDC_CaptchaHttpCommand::FromQuerystring($commandString);
        $responseBody = '';
        switch ($command) {
            case \BDC_CaptchaHttpCommand::GetImage:
                $responseBody = $this->getImage();
                break;
            case \BDC_CaptchaHttpCommand::GetSound:
                $responseBody = $this->getSound();
                break;
            case \BDC_CaptchaHttpCommand::GetValidationResult:
                $responseBody = $this->getValidationResult();
                break;
            case \BDC_CaptchaHttpCommand::GetInitScriptInclude:
                $responseBody = $this->getInitScriptInclude();
                break;
            case \BDC_CaptchaHttpCommand::GetP:
                $responseBody = $this->getP();
                break;
            default:
                \BDC_HttpHelper::BadRequest('command');
                break;
        }

        // disallow audio file search engine indexing
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        echo $responseBody; exit;
    }

    /**
     * Get CAPTCHA object instance.
     *
     * @return object
     */
    private function getBotDetectCaptchaInstance()
    {
        $captchaId = $this->getUrlParameter('c');
        if (is_null($captchaId) || !preg_match('/^(\w+)$/ui', $captchaId)) {
            throw new BadRequestHttpException('Invalid captcha id.');
        }

        $captchaInstanceId = $this->getUrlParameter('t');
        if (is_null($captchaInstanceId) || !(32 == strlen($captchaInstanceId) &&
            (1 === preg_match("/^([a-f0-9]+)$/u", $captchaInstanceId)))) {
            throw new BadRequestHttpException('Invalid instance id.');
        }

        return new BotDetectCaptchaHelper($this->get('session'), $captchaId, $captchaInstanceId);
    }


    /**
     * Generate a Captcha image.
     *
     * @return image
     */
    public function getImage()
    {
        if (is_null($this->captcha)) {
            \BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            \BDC_HttpHelper::BadRequest('instance');
        }


        $libVersion = \BDC_CaptchaBase::$ProductInfo['version'];
        if(version_compare($libVersion, '4.2.0') >= 0) {
            if (!$this->captcha->CaptchaBase->IsInstanceIdExisted($instanceId)) {
                \BDC_HttpHelper::BadRequest('Instance doesn\'t exist in session');
            }
        }

        // image generation invalidates sound cache, if any
        $this->clearSoundData($instanceId);

        // response headers
        \BDC_HttpHelper::DisallowCache();

        // response MIME type & headers
        $mimeType = $this->captcha->CaptchaBase->ImageMimeType;
        header("Content-Type: {$mimeType}");

        // we don't support content chunking, since image files
        // are regenerated randomly on each request
        header('Accept-Ranges: none');

        // image generation
        $rawImage = $this->captcha->CaptchaBase->GetImage($instanceId);
        $this->captcha->CaptchaBase->SaveCodeCollection();

        $length = strlen($rawImage);
        header("Content-Length: {$length}");
        return $rawImage;
    }

    /**
     * Generate a Captcha sound.
     */
    public function getSound()
    {
        if (is_null($this->captcha)) {
            \BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            \BDC_HttpHelper::BadRequest('instance');
        }

        $libVersion = \BDC_CaptchaBase::$ProductInfo['version'];
        if(version_compare($libVersion, '4.2.0') >= 0) {
            if (!$this->captcha->CaptchaBase->IsInstanceIdExisted($instanceId)) {
                \BDC_HttpHelper::BadRequest('Instance doesn\'t exist in session');
            }
        }

        $soundBytes = $this->getSoundData($this->captcha->getCaptchaInstance(), $instanceId);

        if (is_null($soundBytes)) {
            \BDC_HttpHelper::BadRequest('Please reload the form page before requesting another Captcha sound');
            exit;
        }

        $totalSize = strlen($soundBytes);

        // response headers
        \BDC_HttpHelper::SmartDisallowCache();

        // response MIME type & headers
        $mimeType = $this->captcha->CaptchaBase->SoundMimeType;
        header("Content-Type: {$mimeType}");
        header('Content-Transfer-Encoding: binary');

        if (!array_key_exists('d', $_GET)) { // javascript player not used, we send the file directly as a download
            $downloadId = \BDC_CryptoHelper::GenerateGuid();
            header("Content-Disposition: attachment; filename=captcha_{$downloadId}.wav");
        }

        if ($this->detectIosRangeRequest()) { // iPhone/iPad sound issues workaround: chunked response for iOS clients
            // sound byte subset
            $range = $this->getSoundByteRange();
            $rangeStart = $range['start'];
            $rangeEnd = $range['end'];
            $rangeSize = $rangeEnd - $rangeStart + 1;

            // initial iOS 6.0.1 testing; leaving as fallback since we can't be sure it won't happen again:
            // we depend on observed behavior of invalid range requests to detect
            // end of sound playback, cleanup and tell AppleCoreMedia to stop requesting
            // invalid "bytes=rangeEnd-rangeEnd" ranges in an infinite(?) loop
            if ($rangeStart == $rangeEnd || $rangeEnd > $totalSize) {
                \BDC_HttpHelper::BadRequest('invalid byte range');
            }

            $rangeBytes = substr($soundBytes, $rangeStart, $rangeSize);

            // partial content response with the requested byte range
            header('HTTP/1.1 206 Partial Content');
            header('Accept-Ranges: bytes');
            header("Content-Length: {$rangeSize}");
            header("Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$totalSize}");
            return $rangeBytes; // chrome needs this kind of response to be able to replay Html5 audio
        } else if ($this->detectFakeRangeRequest()) {
            header('Accept-Ranges: bytes');
            header("Content-Length: {$totalSize}");
            $end = $totalSize - 1;
            header("Content-Range: bytes 0-{$end}/{$totalSize}");
            return $soundBytes;
        } else { // regular sound request
            header('Accept-Ranges: none');
            header("Content-Length: {$totalSize}");
            return $soundBytes;
        }

    }

    public function GetSoundData($p_Captcha, $p_InstanceId) {
        $shouldCache = (
            ($p_Captcha->SoundRegenerationMode == \SoundRegenerationMode::None) || // no sound regeneration allowed, so we must cache the first and only generated sound
            $this->detectIosRangeRequest() // keep the same Captcha sound across all chunked iOS requests
        );

        if ($shouldCache) {
            $loaded = $this->loadSoundData($p_InstanceId);
            if (!is_null($loaded)) {
                return $loaded;
            }
        } else {
            $this->clearSoundData($p_InstanceId);
        }

        $soundBytes = $this->generateSoundData($p_Captcha, $p_InstanceId);
        if ($shouldCache) {
            $this->saveSoundData($p_InstanceId, $soundBytes);
        }
        return $soundBytes;
    }

    private function generateSoundData($p_Captcha, $p_InstanceId) {
        $rawSound = $p_Captcha->CaptchaBase->GetSound($p_InstanceId);
        $p_Captcha->CaptchaBase->SaveCodeCollection(); // always record sound generation count
        return $rawSound;
    }

    private function saveSoundData($p_InstanceId, $p_SoundBytes) {
        SF_Session_Save("BDC_Cached_SoundData_" . $p_InstanceId, $p_SoundBytes);
    }

    private function loadSoundData($p_InstanceId) {
        return SF_Session_Load("BDC_Cached_SoundData_" . $p_InstanceId);
    }

    private function clearSoundData($p_InstanceId) {
        SF_Session_Clear("BDC_Cached_SoundData_" . $p_InstanceId);
    }


    // Instead of relying on unreliable user agent checks, we detect the iOS sound
    // requests by the Http headers they will always contain
    private function detectIosRangeRequest() {
        $detected = false;

        if(array_key_exists('HTTP_RANGE', $_SERVER) &&
            \BDC_StringHelper::HasValue($_SERVER['HTTP_RANGE'])) {

            // Safari on MacOS and all browsers on <= iOS 10.x
            if(array_key_exists('HTTP_X_PLAYBACK_SESSION_ID', $_SERVER) &&
                \BDC_StringHelper::HasValue($_SERVER['HTTP_X_PLAYBACK_SESSION_ID'])) {
                $detected = true;
            }

            // all browsers on iOS 11.x and later
            if(array_key_exists('User-Agent', $_SERVER) &&
                \BDC_StringHelper::HasValue($_SERVER['User-Agent'])) {
                $userAgent = $_SERVER['User-Agent'];
                if(strpos($userAgent, "iPhone OS") !== false || strpos($userAgent, "iPad") !== false) { // is iPhone or iPad
                    $detected = true;
                }
            }
        }
        return $detected;
    }

    private function getSoundByteRange() {
        // chunked requests must include the desired byte range
        $rangeStr = $_SERVER['HTTP_RANGE'];
        if (!\BDC_StringHelper::HasValue($rangeStr)) {
            return;
        }

        $matches = array();
        preg_match_all('/bytes=([0-9]+)-([0-9]+)/', $rangeStr, $matches);
        return array(
            'start' => (int) $matches[1][0],
            'end'   => (int) $matches[2][0]
        );
    }

    private function detectFakeRangeRequest() {
        $detected = false;
        if (array_key_exists('HTTP_RANGE', $_SERVER)) {
            $rangeStr = $_SERVER['HTTP_RANGE'];
            if (\BDC_StringHelper::HasValue($rangeStr) &&
                preg_match('/bytes=0-$/', $rangeStr)) {
                $detected = true;
            }
        }
        return $detected;
    }

    /**
     * The client requests the Captcha validation result (used for Ajax Captcha validation).
     *
     * @return json
     */
    public function getValidationResult()
    {
        if (is_null($this->captcha)) {
            \BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            \BDC_HttpHelper::BadRequest('instance');
        }

        $mimeType = 'application/json';
        header("Content-Type: {$mimeType}");

        // code to validate
        $userInput = $this->getUserInput();

        // JSON-encoded validation result
        $result = false;
        if (isset($userInput) && (isset($instanceId))) {
            $result = $this->captcha->AjaxValidate($userInput, $instanceId);
            $this->captcha->CaptchaBase->Save();
        }
        $resultJson = $this->getJsonValidationResult($result);

        return $resultJson;
    }

    public function getInitScriptInclude() {
        // saved data for the specified Captcha object in the application
        if (is_null($this->captcha)) {
            \BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            \BDC_HttpHelper::BadRequest('instance');
        }

        // response MIME type & headers
        header('Content-Type: text/javascript');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

        $result = "(function() {\r\n";

        // add init script
        $result .= \BDC_CaptchaScriptsHelper::GetInitScriptMarkup($this->captcha->getCaptchaInstance(), $instanceId);

        // add remote scripts if enabled
        if ($this->captcha->RemoteScriptEnabled) {
            $result .= "\r\n";
            $result .= \BDC_CaptchaScriptsHelper::GetRemoteScript($this->captcha->getCaptchaInstance());
        }

        // close a self-invoking functions
        $result .= "\r\n})();";
        return $result;
    }

    /**
     * @return string
     */
    private function getInstanceId()
    {
        $instanceId = $this->getUrlParameter('t');
        if (!\BDC_StringHelper::HasValue($instanceId) ||
            !\BDC_CaptchaBase::IsValidInstanceId($instanceId)
        ) {
            return;
        }
        return $instanceId;
    }

    /**
     * Extract the user input Captcha code string from the Ajax validation request.
     *
     * @return string
     */
    private function getUserInput()
    {
        // BotDetect built-in Ajax Captcha validation
        $input = $this->getUrlParameter('i');

        if (is_null($input)) {
            // jQuery validation support, the input key may be just about anything,
            // so we have to loop through fields and take the first unrecognized one
            $recognized = array('get', 'c', 't', 'd');
            foreach ($_GET as $key => $value) {
                if (!in_array($key, $recognized)) {
                    $input = $value;
                    break;
                }
            }
        }

        return $input;
    }

    /**
     * Encodes the Captcha validation result in a simple JSON wrapper.
     *
     * @return string
     */
    private function getJsonValidationResult($result)
    {
        $resultStr = ($result ? 'true': 'false');
        return $resultStr;
    }

    /**
     * @param  string  $param
     * @return string|null
     */
    private function getUrlParameter($param)
    {
        return filter_input(INPUT_GET, $param);
    }

    public function GetP() {
        if (is_null($this->captcha)) {
            \BDC_HttpHelper::BadRequest('captcha');
        }

        // identifier of the particular Captcha object instance
        $instanceId = $this->getInstanceId();
        if (is_null($instanceId)) {
            \BDC_HttpHelper::BadRequest('instance');
        }

        // create new one
        $p = new \P($instanceId);

        // save
        SF_Session_Clear($this->captcha->CaptchaBase->getPPersistenceKey($instanceId));
        SF_Session_Save($this->captcha->CaptchaBase->getPPersistenceKey($instanceId), $p);

        // response data
        $response = "{\"sp\":\"{$p->GSP()}\",\"hs\":\"{$p->GHs()}\"}";

        // response MIME type & headers
        header('Content-Type: application/json');
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
        \BDC_HttpHelper::SmartDisallowCache();

        return $response;
    }
}
