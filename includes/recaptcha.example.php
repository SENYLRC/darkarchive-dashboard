<?php
// Reference copy of includes/recaptcha.php.
//
// You do NOT normally need to copy this file. The real includes/recaptcha.php
// reads its settings from .env via config.php's load_env(), so configuration
// lives in .env:
//
//   RECAPTCHA_SITE_KEY=your-recaptcha-site-key
//   RECAPTCHA_PROJECT=your-google-cloud-project-id
//   RECAPTCHA_MIN_SCORE=0.5        # optional, defaults to 0.5
//
// The service account credentials are supplied out of band via
// GOOGLE_APPLICATION_CREDENTIALS (see README section 8).

// NOTE: `use` statements are only legal at the top of a file, never inside a
// function body. An earlier version of this example had them inside
// verify_recaptcha(), which made the file a parse error if it was ever copied
// over the real recaptcha.php.
use Google\Cloud\RecaptchaEnterprise\V1\Client\RecaptchaEnterpriseServiceClient;
use Google\Cloud\RecaptchaEnterprise\V1\Event;
use Google\Cloud\RecaptchaEnterprise\V1\Assessment;
use Google\Cloud\RecaptchaEnterprise\V1\CreateAssessmentRequest;

define('RECAPTCHA_SITE_KEY',  $_ENV['RECAPTCHA_SITE_KEY'] ?? '');
define('RECAPTCHA_PROJECT',   $_ENV['RECAPTCHA_PROJECT']  ?? '');
define('RECAPTCHA_MIN_SCORE', (float)($_ENV['RECAPTCHA_MIN_SCORE'] ?? 0.5));

function verify_recaptcha(string $token, string $action, string &$reason = ''): bool {
    $reason = '';
    if (!$token) { $reason = 'missing'; return false; }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        $reason = 'autoload';
        error_log('reCAPTCHA: vendor/autoload.php not found');
        return false;
    }
    require_once $autoload;

    $client = null;
    try {
        $client      = new RecaptchaEnterpriseServiceClient();
        $projectName = $client->projectName(RECAPTCHA_PROJECT);
        $event       = (new Event())->setSiteKey(RECAPTCHA_SITE_KEY)->setToken($token);
        $assessment  = (new Assessment())->setEvent($event);
        $request     = (new CreateAssessmentRequest())->setParent($projectName)->setAssessment($assessment);
        $response    = $client->createAssessment($request);

        $props = $response->getTokenProperties();
        if (!$props->getValid())            { $reason = 'rejected'; return false; }
        if ($props->getAction() !== $action) { $reason = 'rejected'; return false; }
        if ($response->getRiskAnalysis()->getScore() < RECAPTCHA_MIN_SCORE) {
            $reason = 'score';
            return false;
        }
        return true;
    } catch (Throwable $e) {
        $reason = 'error';
        error_log('reCAPTCHA error: ' . get_class($e) . ': ' . $e->getMessage());
        return false;
    } finally {
        if ($client !== null) {
            try { $client->close(); } catch (Throwable $e) { /* nothing useful to do */ }
        }
    }
}
