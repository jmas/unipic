<?php

// www.google.com.ua/images/srpr/logo11w.png

require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class Unipic {
    private $config = array();

    public function __construct() {
        if (! file_exists('./config.php')) {
            die('Create config.php to run app.');
        }
        $this->config = require('./config.php');
    }
    
    public static function init() {
        return new self;
    }
    
    public function run() {
        $images = glob("./pic/*.{jpg,png,gif}", GLOB_BRACE);
        if (! empty($images)) {
            foreach ($images as $image) {
                $contentType = getimagesize($image);
                $contentType = $contentType['mime'];
                $body = file_get_contents($image);
                $imagePath = pathinfo($image);
                if ($result = $this->uploadToAws($body, $imagePath['basename'], $contentType)) {
                    if (file_put_contents('./data/' . $imagePath['filename'] . '.dat', $result)) {
                        unlink($image);
                    }
                }
            }
        }
    }
    
    public function uploadToAws($body, $name, $contentType)
    {
        $s3 = S3Client::factory(array(
            'key'    => $this->config['awsKey'],
            'secret' => $this->config['awsSecret'],
        ));
        
        try {
            $result = $s3->getCommand('PutObject')
                ->set('Bucket', $this->config['awsBucket'])
                ->set('Key', $name)
                ->set('Body', $body)
                ->set('ACL', 'public-read')
                ->set('ContentType', $contentType)
                ->getResult();
                
            return $result['ObjectURL'];
        } catch (S3Exception $e) {
            echo $e->getMessage();
        }
    }

}

Unipic::init()->run();