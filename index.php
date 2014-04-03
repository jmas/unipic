<?php

/**
 * @author Andries Louw Wolthuizen
 * @site images.weserv.nl
 * @copyright 2013
 **/
error_reporting(E_ALL);
set_time_limit(180);

$allowHosts = array(
    'localhost',
    '127.0.0.1'
);

// if(isset($_SERVER['HTTP_REFERER'])) {
//     $url = parse_url($_SERVER['HTTP_REFERER']);
//     if (! in_array($url['host'], $allowHosts)) {
//         header("HTTP/1.0 500 Internal Server Error");
//         exit;
//     }
// } else {
//     header("HTTP/1.0 500 Internal Server Error");
//     exit;
// }


$img_data = '';

function download_file($path,$fname){
    $options = array(
        CURLOPT_FILE => fopen($fname, 'w'),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_URL => $path,
        CURLOPT_FAILONERROR => true, // HTTP code > 400 will throw curl error
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ImageFetcher/4.0; +http://images.weserv.nl/)',
    );
    
    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $return = curl_exec($ch);
    
    if ($return === false){
        $error = curl_error($ch);
        curl_close($ch);
        unlink($fname);
        $error_code = substr($error,0,3);
        if(in_array($error_code,array('400','403','404','500','502'))){
            trigger_error('cURL Request error: '.$error.' URL: '.$path, E_USER_WARNING);
        }
        return array(false,$error);
    }else{
        curl_close($ch);
        return array(true,NULL);
    }
}

function create_image($path){
    global $img_data;
    $path = str_replace(' ','%20',$path);
    $fname = tempnam('/dev/shm','imo_');
    $curl_result = download_file($path,$fname);
    if($curl_result[0] === false){
        header("HTTP/1.0 404 Not Found");
        $img_data['mime'] = 'text/plain';
        echo 'Error 404: Server could parse the ?url= that you were looking for, error it got: '.$curl_result[1];
        if(isset($_GET['detail'])){ echo '<small><br /><br />Debug: <br />Path: '.$path.'<br />Fname: '.$fname.'</small>'; }
        echo '<small><br /><br />Also, if possible, please replace any occurences of of + in the ?url= with %2B (see <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/2586863-bug">+ bug</a>)</small>';
        die;
    }
    try{
        $img_data = @getimagesize($fname);
        
        if(isset($img_data[2]) && in_array($img_data[2],array(IMAGETYPE_JPEG,IMAGETYPE_GIF,IMAGETYPE_PNG))){
            if($img_data[0]*$img_data[1] > 71000000){
                unlink($fname);
                throw new Exception('Image too large for processing. Width x Height should be less than 70 megapixels.');
            }
            switch($img_data[2]){
                case IMAGETYPE_JPEG:
                    $img_data['ext'] = 'jpg';
                    $img_data['exif'] = @exif_read_data($fname);
                    $gd_stream = imagecreatefromjpeg($fname);
                break;
                
                case IMAGETYPE_GIF:
                    $img_data['ext'] = 'gif';
                    $gd_stream = imagecreatefromgif($fname);
                break;
                
                case IMAGETYPE_PNG:
                    $img_data['ext'] = 'png';
                    $gd_stream = imagecreatefrompng($fname);
                break;
            }
        }elseif(isset($img_data[2]) && $img_data[2] == IMAGETYPE_BMP){
            $im = new Imagick($fname);
            $im->setImageFormat('png');
            $im->stripImage();
            $tmpimagick = tempnam('/dev/shm','imb_');
            $im->writeImage($tmpimagick);
            $im->clear();
            $im->destroy();
            rename($tmpimagick,$fname);
            $img_data = @getimagesize($fname);
            $img_data['ext'] = 'jpg';
            $img_data['mime'] = 'image/jpeg';
            $gd_stream = imagecreatefrompng($fname);
        }else{
            unlink($fname);
            throw new Exception('This is no JPG/GIF/PNG/BMP-file!');
        }
        
        if($gd_stream === false){
            unlink($fname);
            throw new Exception('Image format not valid.');
        }
        
        unlink($fname);
        return $gd_stream;
    }catch(Exception $e){
        header("HTTP/1.0 404 Not Found");
        $img_data['mime'] = 'text/plain';
        echo 'Error 404: Server could parse the ?url= that you were looking for, because it isn\'t a valid (supported) image, error: '.$e->getMessage();
        if(isset($_GET['detail'])){ echo '<small><br /><br />Debug: <br />Path: '.$path.'<br />Fname: '.$fname.'</small>'; }
        echo '<small><br /><br />Also, if possible, please replace any occurences of of + in the ?url= with %2B (see <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/2586863-bug">+ bug</a>)</small>';
        if($e->getMessage() != 'This is no JPG/GIF/PNG/BMP-file!'){
            trigger_error('URL failed. Message: '.$e->getMessage().' URL: '.$path,E_USER_WARNING);
        }
        die;
    }
}

function show_image($image,$quality){
    global $img_data;
    switch($img_data['mime']){
        case 'image/jpeg':
            return imagejpeg($image,NULL,$quality);
        break;
        
        case 'image/gif':
            return imagegif($image);
        break;
        
        case 'image/png':
            imagesavealpha($image,true);
            return imagepng($image);
        break;
    }
}

function resize_image($image,$max_height,$max_width,$transformation,$alignment,$trim,$circle,$interlace){
    global $img_data;
    
    $cur_width = $img_data[0];
    $cur_height = $img_data[1];
    
    if($trim > 0){
        $ta = imageTrimmedBox($cur_width,$cur_height,$image,$trim);
        if($ta !== false){
            $cur_width = $ta['w'];
            $cur_height = $ta['h'];
        }
    }
    
    if(($max_height+$max_width) > 0 || $trim > 0){
        $new = set_dimension($cur_width,$cur_height,$max_width,$max_height,$transformation,$alignment);
        
        if($trim > 0 && $ta !== false){
            $new['org_x'] += $ta['l'];
            $new['org_y'] += $ta['t'];
        }
        
        // This is transparency-preserving magic!
        $image_resized = imagecreatetruecolor($new['width'],$new['height']);
        if(($img_data[2] == IMAGETYPE_GIF) || ($img_data[2] == IMAGETYPE_PNG)){
            $tidx = imagecolortransparent($image);
            $palletsize = imagecolorstotal($image);
            if($tidx >= 0 && $tidx < $palletsize){
                $trnprt_color  = imagecolorsforindex($image, $tidx);
                $tidx  = imagecolorallocate($image_resized, $trnprt_color['red'], $trnprt_color['green'], $trnprt_color['blue']);
                imagefill($image_resized, 0, 0, $tidx);
                imagecolortransparent($image_resized, $tidx);
            }elseif($img_data[2] == IMAGETYPE_PNG){
                imagealphablending($image_resized, false);
                $color = imagecolorallocatealpha($image_resized, 0, 0, 0, 127);
                imagefill($image_resized, 0, 0, $color);
                imagesavealpha($image_resized, true);
            }
        }
        
        imagecopyresampled($image_resized, $image, 0, 0, $new['org_x'], $new['org_y'], $new['width'], $new['height'],$new['org_width'], $new['org_height']);
        imagedestroy($image);
    }else{
        $image_resized = $image;
    }
    
    if($circle == '_circle'){
        $image_resized = imageCircleEffect($new['width'],$new['height'],$image_resized);
    }
    
    if($interlace == '_il'){
        imageinterlace($image_resized,1);
    }
    
    return $image_resized;               
}

function set_dimension($imageWidth,$imageHeight,$maxWidth,$maxHeight,$transformation,$alignment){
    if($transformation == 'fit' || $transformation == 'fitup'){
        $maxWidth = ($maxWidth > 0) ? $maxWidth : $maxHeight*100;
        $maxHeight = ($maxHeight > 0) ? $maxHeight : $maxWidth*100;
        
        $wRatio = $imageWidth / $maxWidth;
        $hRatio = $imageHeight / $maxHeight;
        $maxRatio = max($wRatio, $hRatio);
        if($maxRatio > 1 || $transformation == 'fitup') {
            $new['width'] = $imageWidth / $maxRatio;
            $new['height'] = $imageHeight / $maxRatio;
        }else{
            $new['width'] = $imageWidth;
            $new['height'] = $imageHeight;
        }
        
        $new['org_width'] = $imageWidth;
        $new['org_height'] = $imageHeight;
        
        $new['org_x'] = 0;
        $new['org_y'] = 0;  
    }elseif($transformation == 'square' || $transformation == 'squaredown'){
        $new['width'] = ($maxWidth > 0) ? $maxWidth : $imageWidth;
        $new['height'] = ($maxHeight > 0) ? $maxHeight : $imageHeight;
        
        if($transformation == 'squaredown'){
            if($imageWidth <= $new['width']){
                $new['width'] = $imageWidth;
            }
            if($imageHeight <= $new['height']){
                $new['height'] = $imageHeight;
            }
        }
        
        $wRatio = $imageWidth / $maxWidth;
        $hRatio = $imageHeight / $maxHeight;
        
        $ratioComputed      = $imageWidth / $imageHeight;
        $cropRatioComputed  = $new['width'] / $new['height'];
        
        if ($ratioComputed < $cropRatioComputed){
            $new['org_width'] = $imageWidth;
            $new['org_height'] = $imageWidth/$cropRatioComputed;
            
            $new['org_x'] = 0;
            if($alignment == 't'){
                $new['org_y'] = 0;
            }elseif($alignment == 'b'){
                $new['org_y'] = ($imageHeight - $new['org_height']);
            }else{
                $new['org_y'] = ($imageHeight - $new['org_height']) / 2;
            }
        }elseif($ratioComputed > $cropRatioComputed){
            $new['org_width'] = $imageHeight*$cropRatioComputed;
            $new['org_height'] = $imageHeight;
            
            if($alignment == 'r'){
                $new['org_x'] = 0;
            }elseif($alignment == 'l'){
                $new['org_x'] = ($imageWidth - $new['org_width']);
            }else{
                $new['org_x'] = ($imageWidth - $new['org_width']) / 2;
            }
            $new['org_y'] = 0;
        }else{
            $new['org_width'] = $imageWidth;
            $new['org_height'] = $imageHeight;
            
            $new['org_x'] = 0;
            $new['org_y'] = 0;
        }
    }elseif($transformation == 'absolute'){
        $new['org_width'] = $imageWidth;
        $new['org_height'] = $imageHeight;
        
        $new['width'] = ($maxWidth > 0) ? $maxWidth : $imageWidth;
        $new['height'] = ($maxHeight > 0) ? $maxHeight : $imageHeight;
        
        $new['org_x'] = 0;
        $new['org_y'] = 0;
    }
    return $new;                 
}

function imageTrimmedBox($cur_width,$cur_height,$img,$t,$hex=null){
    if($hex == null) $hex = imagecolorat($img, 2, 2); // 2 pixels in to avoid messy edges

    $r = ($hex >> 16) & 0xFF;
    $g = ($hex >> 8) & 0xFF;
    $b = $hex & 0xFF;
    $c = round(($r+$g+$b)/3); // average of rgb is good enough for a default
    
    $width = $cur_width;
    $height = $cur_height;
    $b_top = 0;
    $b_lft = 0;
    $b_btm = $height - 1;
    $b_rt = $width - 1;
    
    //top
    for(; $b_top < $height; ++$b_top) {
        for($x = 0; $x < $width; ++$x) {
            $rgb = imagecolorat($img, $x, $b_top);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if (
                ($r < $c-$t || $r > $c+$t) && // red not within tolerance of trim colour 
                ($g < $c-$t || $g > $c+$t) && // green not within tolerance of trim colour 
                ($b < $c-$t || $b > $c+$t) // blue not within tolerance of trim colour
            ){
                break 2;
            }
        }
    }
    
    // return false when all pixels are trimmed
    if ($b_top == $height) return false;
    
    // bottom
    for(; $b_btm >= 0; --$b_btm) {
        for($x = 0; $x < $width; ++$x) {
            $rgb = imagecolorat($img, $x, $b_btm);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if (
                ($r < $c-$t || $r > $c+$t) && // red not within tolerance of trim colour 
                ($g < $c-$t || $g > $c+$t) && // green not within tolerance of trim colour 
                ($b < $c-$t || $b > $c+$t) // blue not within tolerance of trim colour
            ){
                break 2;
            }
        }
    }
    
    // left
    for(; $b_lft < $width; ++$b_lft) {
        for($y = $b_top; $y <= $b_btm; ++$y) {
            $rgb = imagecolorat($img, $b_lft, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if (
                ($r < $c-$t || $r > $c+$t) && // red not within tolerance of trim colour 
                ($g < $c-$t || $g > $c+$t) && // green not within tolerance of trim colour 
                ($b < $c-$t || $b > $c+$t) // blue not within tolerance of trim colour
            ){
                break 2;
            }
        }
    }
    
    // right
    for(; $b_rt >= 0; --$b_rt) {
        for($y = $b_top; $y <= $b_btm; ++$y) {
            $rgb = imagecolorat($img, $b_rt, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if (
                ($r < $c-$t || $r > $c+$t) && // red not within tolerance of trim colour 
                ($g < $c-$t || $g > $c+$t) && // green not within tolerance of trim colour 
                ($b < $c-$t || $b > $c+$t) // blue not within tolerance of trim colour
            ){
                break 2;
            }
        }
    }
    
    $b_btm++;
    $b_rt++;
    return array(
        'l' => $b_lft,
        't' => $b_top,
        'r' => $b_rt,
        'b' => $b_btm,
        'w' => $b_rt - $b_lft,
        'h' => $b_btm - $b_top
    );
}

function imageCircleEffect($cur_width,$cur_height,$img,$hex=null){
    global $img_data;
    
    // Create a black image with a transparent ellipse, and merge with destination
    $mask = imagecreatetruecolor($cur_width, $cur_height);
    $maskTransparent = imagecolorallocate($mask, 255, 0, 255);
    imagecolortransparent($mask, $maskTransparent);
    imagefilledellipse($mask, $cur_width / 2, $cur_height / 2, $cur_width, $cur_height, $maskTransparent);
    imagecopymerge($img, $mask, 0, 0, 0, 0, $cur_width, $cur_height, 100);

    // Fill each corners of destination image with transparency
    $dstTransparent = imagecolorallocatealpha($img, 255, 0, 255, 127);
    imagefill($img, 0, 0, $dstTransparent);
    imagefill($img, $cur_width - 1, 0, $dstTransparent);
    imagefill($img, 0, $cur_height - 1, $dstTransparent);
    imagefill($img, $cur_width - 1, $cur_height - 1, $dstTransparent);
    
    imagedestroy($mask);
    $img_data['ext'] = 'png';
    $img_data['mime'] = 'image/png';
    return $img;
}

function check_utf8($str){
    $len = strlen($str);
    for($i = 0; $i < $len; $i++){
        $c = ord($str[$i]);
        if($c > 128){
            if(($c > 247)) return false;
            elseif($c > 239) $bytes = 4;
            elseif($c > 223) $bytes = 3;
            elseif($c > 191) $bytes = 2;
            else return false;
            if(($i + $bytes) > $len) return false;
            while ($bytes > 1) {
                $i++;
                $b = ord($str[$i]);
                if($b < 128 || $b > 191) return false;
                $bytes--;
            }
        }
    }
    return true;
}

function custom_base64($input){
    global $img_data;
    if(!empty($input)){
        $return = 'data:'.$img_data['mime'];
        if($img_data['mime'] != 'text/plain'){
            $return .= ';base64,'.base64_encode($input);
        }else{
            $return .= ';Error';
        }
        return $return;
    }else{
        return;
    }
}

function checkImage($filename)
{
    $picDirPath = './pic/';
    $datFileName = './data/' . $filename . '.dat';
    $types = array('jpg', 'png', 'gif');

    if (file_exists($datFileName)) {
        $data = file($datFileName);
        header("Location:" . $data[0]);
        exit;
    } else {
        $fName = null;

        foreach ($types as $type) {
            if (file_exists($picDirPath . $filename . '.' . $type)) {
                $fName = $filename . '.' . $type;
                break;
            }
        }

        if ($path) {
            header("Location: pic/" . $fName);
            exit;
        }
    }
}

function saveImage($image, $quality, $filename){
    global $img_data;
    
    switch($img_data['mime']){
        case 'image/jpeg':
            return imagejpeg($image, './pic/' . $filename . '.jpg', $quality) ? './pic/' . $filename . '.jpg' : false; 
        break;
        
        case 'image/gif':
            return imagegif($image, './pic/' . $filename . '.gif') ? './pic/' . $filename . '.gif' : false;
        break;
        
        case 'image/png':
            imagesavealpha($image,true);
            return imagepng($image, './pic/' . $filename . '.png') ? './pic/' . $filename . '.png' : false;
        break;
    }
}

if(!empty($_GET['url'])){

    $h = (empty($_GET['h']) OR !ctype_digit($_GET['h'])) ? '0' : $_GET['h'];
    $w = (empty($_GET['w']) OR !ctype_digit($_GET['w'])) ? '0' : $_GET['w'];
    $t = (empty($_GET['t']) OR !in_array($_GET['t'],array('fit','fitup','square','squaredown','absolute'))) ? 'fit' : $_GET['t'];
    $a = (empty($_GET['a']) OR !in_array($_GET['a'],array('t','b','r','l'))) ? 'c' : $_GET['a'];
    $q = (empty($_GET['q']) OR !ctype_digit($_GET['q']) OR $_GET['q'] > 95 OR $_GET['q'] < 0) ? '85' : $_GET['q'];
    $c = (isset($_GET['circle'])) ? '_circle' : '0';
    $il = (isset($_GET['il'])) ? '_il' : '';
       

    
    //Trim
    if(isset($_GET['trim'])){
        // if tolerance ($_GET['trim']) isn't a number between 0 - 255 use 10 as default
        if(empty($_GET['trim']) || !ctype_digit($_GET['trim']) || $_GET['trim'] < 0 || $_GET['trim'] > 255){
            $s = 10;
        }else{
            $s = (int)$_GET['trim'];
        }
    }else{
        $s = 0;
    }
    
    
    $filename = md5($_GET['url']) . '_' . $w . '_' . $h . '_' . $t . '_' . $a . '_' . $c . '_' . $q;
    checkImage($filename);
    
    //SSL code
    if(substr($_GET['url'],0,4) == 'ssl:'){
        $ssl = true;
        $_GET['url'] = substr($_GET['url'],4);
        $parts = parse_url('https://'.$_GET['url']);
    }else{
        $ssl = false;
        $parts = parse_url('http://'.$_GET['url']);
    }
    
    //IDN-rewriting
    // if(idn_to_ascii($parts['host']) == ''){
    //  $parts['host'] = utf8_encode($parts['host']);
    // }
    
    if(isset($_GET['encoding']) && $_GET['encoding'] == 'base64'){
        ob_start('custom_base64');
        $_GET['fnr'] = true;
    }
    
    if(!isset($parts['scheme'])){
        header("HTTP/1.0 404 Not Found");
        $img_data['mime'] = 'text/plain';
        echo 'Error 404: Server could parse the ?url= that you were looking for, because it isn\'t a valid url.';
        trigger_error('URL failed, unable to parse. URL: '.$_GET['url'],E_USER_WARNING);
        die;
    }
    
    
    $_GET['url'] = $parts['scheme'].'://'.$parts['host'];//idn_to_ascii($parts['host']);
    if(isset($parts['path'])){
        $parts['path'] = (check_utf8($parts['path']) === false) ? utf8_encode($parts['path']) : $parts['path'];
        $_GET['url'] .= $parts['path'];
        $_GET['url'] .= isset($parts['query']) ? '?'.$parts['query'] : '';
    }
    
    $image = create_image($_GET['url']);

    
    //Change orientation on EXIF-data
    if(isset($img_data['exif'])){
        if(isset($img_data['exif']['Orientation']) && !empty($img_data['exif']['Orientation'])){
            switch($img_data['exif']['Orientation']){
                case 8:
                    $image = imagerotate($image,90,0);
                    
                    //Change source dimensions
                    $temp_w = $img_data[0];
                    $img_data[0] = $img_data[1];
                    $img_data[1] = $temp_w;
                    unset($temp_w);
                break;
                case 3:
                    $image = imagerotate($image,180,0);
                break;
                case 6:
                    $image = imagerotate($image,-90,0);
                    
                    //Change source dimensions
                    $temp_w = $img_data[0];
                    $img_data[0] = $img_data[1];
                    $img_data[1] = $temp_w;
                    unset($temp_w);
                break;
            }
        }
    }
    
    //Resize only when needed
    if($h > 0 || $w > 0 || $s > 0 || $c == '_circle' || $il == '_il'){
        $image = resize_image($image,$h,$w,$t,$a,$s,$c,$il);
    }
        
    $output_formats = array('png' => 'image/png','jpg' => 'image/jpeg','gif' => 'image/gif');
    if(isset($_GET['output']) && isset($output_formats[$_GET['output']])){
        $img_data['mime'] = $output_formats[$_GET['output']];
    }
    
    // header('Expires: '.gmdate("D, d M Y H:i:s", (time()+2678400)).' GMT'); //31 days
    // header('Cache-Control: max-age=2678400'); //31 days
    // if(isset($_GET['encoding']) && $_GET['encoding'] == 'base64'){
        // header('Content-type: text/plain');
    // }else{
        // header('Content-type: '.$img_data['mime']);
    // }
    //show_image($image, $q);
    
    if($savePath = saveImage($image, $q, $filename)) {
        header("Location:" . $savePath);
    }
    exit;
}else{
?>
<!doctype html>
<html>
    <head>
        <title>Image cache &amp; resize proxy</title>
        <meta http-equiv="Content-Type" content="text/html;charset=UTF-8" />
        <style type="text/css">
            * {
                margin:0;
                padding:0;
            }
            
            html, body {
                width:100%;
            }

            body {
                font-family: sans-serif;
                background: #f1f3f7;
            }

            /*h1 {
                font-size:14px;
            }

            h2 {
                font-size:12px;
            }*/

            /*b {
                color:#a72376;
            }*/

            h1, h2 {
                margin-bottom: .5em;
            }

            /*p, hr {
                font-size:12px;
                margin-top:15px;
            }*/

            small.nl,
            .example small {
                display:block;
                margin-top:5px;
            }

            hr {
                clear:both;
                color:#eee;
                background-color:#eee;
                height:1px;
                border:none;
                margin: 1.5em 0;
            }
            
            #c, #s {
                /*font-size:13px;*/
                color:#ccc;
                margin:9px 6px;
                float:left;
            }

            #s {
                float:right;
            }
            
            .new {
                display:block;
                position:relative;
                float:right;
            }

            a {
                color:#6fa8dc;
                text-decoration:none;
            }

            a:hover {
                text-decoration:underline;
            }
            
            #content {
                margin:50px auto;
                width:450px;
                padding:20px;
                background-color:#fff;
                color:#292929;
                border-radius: 3px;
            }

            #content p {
                margin-bottom: 1.5em;
            }

            #content ul {
                margin: 1.5em 0;
                font-size: 1em;
            }

            #content ul li {
                margin: .5em 0;
                margin-left: 1.2em;
            }
            
            a.uptime {
                display:block;
                width:100%;
                margin:10px 0 -10px 0;
                text-align:center;
                /*font-size:10px;*/
            }

            .optional {
                color:#aaa;
            }
            
            .example {
                float:left;
                width:112px;
                margin:10px 0;
                /*font-size:12px;*/
                text-align:center;
            }

            .example img {
                border: 1px solid #eee;
            }

            #mb {
                margin:14px 0;
            }
        </style>
    </head>
    <body>
        <div id="content">
            <h1>Image <b>cache</b> &amp; <b>resize</b> proxy</h1>
            
            <p>Our servers resize your image, cache it worldwide, and display it.</p>
            <ul>
                <li>We don't support animated images (yet).</li>
                <li>We do support GIF, JPEG, PNG, BMP and even transparent images!</li>
                <li>Full IPv6 support<?php /*<a href="http://ipv6-test.com/validate.php?url=images.weserv.nl" rel="nofollow">serving dual stack</a>, and supporting <a href="//images.weserv.nl/?url=ipv6.google.com/logos/logo.gif">IPv6-only origin hosts</a> */ ?>.</li>
                <li>SSL support, you can use HTTPS.</li>
            </ul>

            <?php /*<p>
                <small class="sslnote">This can be very useful for embedding HTTP images on HTTPS websites. Avoid mixed
                content warnings by using <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/4277688-bug-redirecting-the-browser-on-facebook-fbcdn-lin"><b>&amp;fnr</b></a>.
                HTTPS origin hosts can be used by <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/2693328-add-support-to-fetch-images-over-https">prefixing the hostname with ssl:</a>
                We're part of the <a href="https://www.cloudflare.com/">CloudFlare</a> community. Images are being cached and delivered straight from <a href="https://www.cloudflare.com/network-map">23 global datacenters</a>.
                This ensures the fastest load times and best performance.</small>
            </p> */ ?>
            
            <hr />
            
            <p><strong>Requesting an image:</strong><p>

            <ul>
                <li><b>?url= </b>(URL encoded) link to your image, without http://</li>
                <li><b>&amp;h= </b>maximum height of image <span class="optional">(optional)</span></li>
                <li><b>&amp;w= </b>maximum width of image <span class="optional">(optional)</span></li>
            </ul>

            <small class="nl">Example: &lt;img src="http://localhost/?url=www.google.nl/logos/logo.gif&amp;h=30" /&gt;</small></p>
            
            <hr />
            
            <p><strong>Choose JPEG compression level:</strong><p>
            
             <ul>
                <li>
                    <b>&amp;q= </b>any value between 0 and 95  <span class="optional">(optional)</span><br />
                    <small class="nl">This parameter is only effective for JPEG-images. If you don't specify a quality it defaults to 85.</small>
                </li>
            </ul>
            
            <hr />
            
            <p><strong>Choose transformation:</strong></p>

            <ul>
                <li><b>&amp;t= </b>fit <small>(default)</small> <b>or</b> fitup <b>or</b> square <b>or</b> squaredown <b>or</b> absolute <span class="optional">(optional)</span></li>
            </ul>
            
            <p><small class="nl">Examples:</small></p>

            <div class="example">
                <img src="?url=www.google.nl/logos/logo.gif&amp;h=45" alt="" />
                <small>Original</small>
            </div>

            <div class="example">
                <img src="?url=www.google.nl/logos/logo.gif&amp;h=45&amp;w=45&amp;t=fit" alt="" id="mb" />
                <small>h=45 w=45<br />t=fit</small>
            </div>

            <div class="example">
                <img src="?url=www.google.nl/logos/logo.gif&amp;h=45&amp;w=45&amp;t=square" alt="" />
                <small>h=45 w=45<br />t=square</small>
            </div>

            <div class="example">
                <img src="?url=www.google.nl/logos/logo.gif&amp;h=45&amp;w=45&amp;t=absolute" alt="" />
                <small>h=45 w=45<br />t=absolute</small>
            </div>

            <p><small class="nl">t=fitup is the same as t=fit, but allows overscaling of the original image.<br />t=squaredown is the same as t=square, but won't upscale the original image.</small></p>
            
            <hr />
            
            <p><strong>Choose image alignment:</strong></p>

            <ul>
                <li>
                    <b>&amp;a= </b>t <small>(top)</small> <b>or</b> b <small>(bottom)</small> <b>or</b> l <small>(left)</small> <b>or</b> r <small>(right)</small> <span class="optional">(optional)</span><br />
                    <small class="nl">Only works when t=square, and the image needs to be cropped in height (t or b) or width (l or r). <?php /* For more information, please see the suggestion on our UserVoice forum: <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/2570350-aligning">#2570350 - Aligning</a>*/ ?></small>
                </li>
            </ul>
            
            <hr />
            
            <p><strong>Trim borders from image:</strong></p>

            <ul>
                <li>
                    <b>&amp;trim= </b>sensitivity between 0 and 255 <span class="optional">(optional)</span><br /><small class="nl">You also can specify just <b>&amp;trim</b>, which defaults to a sensitivity of 10.
                    <?php /* <br />More info: <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/3083264-able-to-remove-black-white-whitespace">#3083264 - Able to remoce black/white whitespace</a> */ ?>
                    </small>
                </li>
            </ul>
            
            <hr />
            
            <p><strong>Circle crop image:</strong></p>

            <ul>
                <li>
                    <b>&amp;circle </b> <span class="optional">(optional)</span><br /><small class="nl">Crops the image to a circle.
                    <?php /* <br />More info: <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/3910149-add-circle-effect-to-photos">#3910149 - Add circle effect to photos</a>*/ ?>
                    </small>
                </li>
            </ul>
            
            <hr />
            
            <p><strong>Interlacing and progressive rendering:</strong></p>

            <ul>
                <li>
                    <b>&amp;il </b> <span class="optional">(optional)</span><br /><small class="nl">Adds interlacing to GIF and PNG. JPEG's become progressive.
                    <?php /* <br />More info: <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/3998911-add-parameter-to-use-progressive-jpegs">#3998911 - Add parameter to use progressive JPEGs</a>*/ ?>
                    </small>
                </li>
            </ul>
            
            <hr />
            
            <p><strong>Return the image as base64-encoded string:</strong> <?php /* <b class="new">New!</b> */ ?></p>
            
            <ul>
                <li>
                    <b>&amp;encoding= </b>base64 <span class="optional">(optional)</span><br /><small class="nl">Encodes the image to be used directly in the src= of the &lt;img&gt;-tag.
                    <?php /* <br />More info: <a href="//http://imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/4522336-return-image-base64-encoded">#4522336 - Return image base64 encoded</a>*/ ?>
                    </small>
                </li>
            </ul>
            
            <hr />
            
            <p><strong>Choose the output format:</strong> <?php /* <b class="new">New!</b> */ ?></p>
            
            <ul>
                <li>
                    <b>&amp;output= </b>png <b>or</b> jpg <b>or</b> gif <span class="optional">(optional)</span><br /><small class="nl">Choose how the proxy outputs the thumbnail, if none is given, it will honor the origin image format. <?php /* More info: <a href="//imagesweserv.uservoice.com/forums/144259-images-weserv-nl-general/suggestions/5097964-format-conversion">#5097964 - Format conversion</a>*/ ?>
                    </small>
                </li>
            </ul>
        </div>
    </body>
</html>
<?php
}
?>