<?php
namespace lib\Paykka\Api;

/**
 * @since 1.5.0
 */
class Browser {

    public string $user_agent;
    public string $color_depth;
    public string $language;
    public bool $java_enabled;
    public string $device_type;
    public string $terminal_type;
    public string $device_os;
    public string $timezone_offset;
    public string $screen_height;
    public string $screen_width;
    public string $cookies;
    public string $device_finger_print_id;
    public string $fraud_detection_id;

    public function __construct() {
        $this->user_agent = '';
        $this->color_depth = '24'; // 默认24位色深
        $this->language = 'en-US';
        $this->java_enabled = false; // JavaScript 需要前端传递
        $this->device_type = 'PC';
        $this->terminal_type = 'WEB';
        $this->device_os = 'WINDOWS';
        $this->timezone_offset = '+00:00';
        $this->screen_height ='1080';
        $this->screen_width = '1920';
        $this->cookies ='';
        // $this->device_finger_print_id = md5('device_finger_print_id');
        $this->device_finger_print_id = md5(uniqid());
    }

    public function __get($property) {
        return $this->$property ?? null;
    }

    public function __set($property, $value) {
        $this->$property = $value;
    }

    private function detectDeviceType(): string {
        $userAgent = strtolower($this->user_agent);
        if (strpos($userAgent, 'mobile') !== false) {
            return 'MOBILE';
        } elseif (strpos($userAgent, 'tablet') !== false) {
            return 'TABLET';
        }
        return 'PC';
    }

    private function detectOS(): string {
        $userAgent = strtolower($this->user_agent);
        if (strpos($userAgent, 'windows') !== false) {
            return 'WINDOWS';
        } elseif (strpos($userAgent, 'mac') !== false) {
            return 'MACOS';
        } elseif (strpos($userAgent, 'linux') !== false) {
            return 'LINUX';
        } elseif (strpos($userAgent, 'android') !== false) {
            return 'ANDROID';
        } elseif (strpos($userAgent, 'iphone') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'IOS';
        }
        return 'UNKNOWN';
    }
    
}
