<?php
/**
 * 图片处理组件 <摘自ThinkPHP扩展>
 * 
 * @author Will Lee <im.will.lee@gmail.com>
 * @copyright LeeCMS
 * @since PHP 5.3.X CakePHP 2.X
 */

App::uses('Component', 'Controller');

class ImageComponent extends Component {

/**
 * 导入component
 * 
 * @var array
 */
	public $components = array();

/**
 * getimagesize函数返回图片类型扩展名定义
 * 
 * @var array
 */
	protected $_imageExts = array(
		1 => 'gif',
		2 => 'jpg',
		3 => 'png',
		4 => 'swf',
		5 => 'psd',
		6 => 'bmp',
		7 => 'tiff_i',
		8 => 'tiff_m',
		9 => 'jpc',
		10 => 'jp2',
		11 => 'jpx',
		12 => 'jb2',
		13 => 'swc',
		14 => 'iff',
		15 => 'wbmp',
		16 => 'xbm'
	);

/**
 * 初始化组件
 * 
 * @param  Controller $controller 控制器
 * @return void
 */
	public function initialize(Controller $controller) {
		$this->Controller = $controller;
	}

/**
 * 取得图像信息
 * 
 * @param string $image 图像文件名
 * @return mixed
 */
	public function getImageInfo($filename) {
		$img = getimagesize($filename);
		if ($img !== false) {
			if (empty($this->_imageExts[$img[2]])) {
				return false;
			}
			return array(
				'width' => $img[0],
				'height' => $img[1],
				'ext' => $this->_imageExts[$img[2]],
				'size' => filesize($filename),
				'mime' => $img['mime']
			);
		} else {
			return false;
		}
	}

/**
 * 为图片添加水印
 * 
 * @param string $source 原文件名
 * @param string $water  水印图片
 * @param array  $option 水印参数
 * @return void
 */
	public function watermark($source, $watermark, $option = array()) {
		$default = array('position' => 1, 'alpha' => 80);
		$option = am($default, $option);
		// 检查文件是否存在
		if (!file_exists($source) || !file_exists($watermark)) {
			return false;
		}

		// 图片信息
		$sourceInfo = $this->getImageInfo($source);
		$watermarkInfo = $this->getImageInfo($watermark);

		// 如果图片小于水印图片，不生成图片
		if ($sourceInfo['width'] < $watermarkInfo['width'] || $sourceInfo['height'] < $watermarkInfo['height']) {
			return false;
		}

		// 建立图像
		$sourceIm = $this->__switchCreateImageFunc($source, $sourceInfo['mime']);
		if (!$sourceIm) {
			return false;
		}
		$watermarkIm = $this->__switchCreateImageFunc($watermark, $watermarkInfo['mime']);
		if (!$watermarkIm) {
			return false;
		}

		// 设定图像的混色模式
		imagealphablending($watermarkIm, true);

		// 图像位置,默认为左上角左对齐
		$pos = $this->__getWatermarkPosition($option['position'], $sourceInfo, $watermarkInfo);

		// 生成混合图像
		imagecopymerge($sourceIm, $watermarkIm, $pos['x'], $pos['y'], 0, 0, $watermarkInfo['width'],
		$watermarkInfo['height'], $option['alpha']);
		// 没有指定保存名 原文件保存
		$savePath = $source;
		if (!empty($option['save_path'])) {
			$savePath = $option['save_path'];
		}
		// 删除原有图片
		if (file_exists($savePath)) {
			unlink($savePath);
		}
		// 保存新图片
		$saveInfo = pathinfo($savePath);
		$this->__saveImage($sourceIm, $savePath, $saveInfo['extension']);
		imagedestroy($sourceIm);
		imagedestroy($watermarkIm);
		return true;
	}

/**
 * 生成缩略图 缩放比列自适应
 * 
 * @param  string  $source    原始图片路径
 * @param  array   $option    生成缩略图参数
 * @param  integer $interlace 启用隔行扫描
 * @return void
 */
	public function thumb($source, $option = array(), $interlace = 1) {
		// 原始图片信息
		$src = $this->getImageInfo($source);
		if ($src === false) {
			return false;
		}
		// 缩略图扩展名获取
		$ext = $option['ext'] !== false ? $option['ext'] : $src['ext'];

		// 计算缩放比例
		$scale = min($option['width'] / $src['width'], $option['height'] / $src['height']);
		if ($scale >= 1) {
			// 超过原图大小不再缩略
			$width = $src['width'];
			$height = $src['height'];
		} else {
			// 缩略图尺寸
			$width = intval($src['width'] * $scale);
			$height = intval($src['height'] * $scale);
		}

		// 图像标识符
		$im = $this->__switchCreateImageFunc($source, $src['mime']);
		if (!$im) {
			return false;
		}
		// 创建缩略图
		if ($ext != 'gif' && function_exists('imagecreatetruecolor')) {
			$thumb = imagecreatetruecolor($width, $height);
		} else {
			$thumb = imagecreate($width, $height);
		}

		// png和gif的透明处理
		if ('png' == $ext) {
			// 取消默认的混色模式（为解决阴影为绿色的问题）
			imagealphablending($thumb, false);
			// 设定保存完整的 alpha 通道信息（为解决阴影为绿色的问题）
			imagesavealpha($thumb, true);
		} elseif ('gif' == $ext) {
			$trnprtIndx = imagecolortransparent($im);
			$palletSize = imagecolorstotal($im);
			if ($trnprtIndx >= 0 && $trnprtIndx < $palletSize) {
				// 透明
				$trnprtColor = imagecolorsforindex($im, $trnprtIndx);
				$trnprtIndx = imagecolorallocate($thumb, $trnprtColor['red'], $trnprtColor['green'], $trnprtColor['blue']);
				imagefill($thumb, 0, 0, $trnprtIndx);
				imagecolortransparent($thumb, $trnprtIndx);
			}
		}

		// 复制图片
		if (function_exists('imagecopyresampled')) {
			imagecopyresampled($thumb, $im, 0, 0, 0, 0, $width, $height, $src['width'], $src['height']);
		} else {
			imagecopyresized($thumb, $im, 0, 0, 0, 0, $width, $height, $src['width'], $src['height']);
		}

		// 对jpeg图形设置隔行扫描
		if ('jpg' == $ext || 'jpeg' == $ext) {
			imageinterlace($thumb, $interlace);
		}

		// 生成图片
		$savePath = $option['save_dir'] . $option['save_name'] . '.' . $ext;
		$this->__saveImage($thumb, $savePath, $ext);
		imagedestroy($thumb);
		imagedestroy($im);
		return true;
	}

/**
 * 生成缩略图 缩放比列自适应
 * 
 * @param  string  $source    原始图片路径
 * @param  array   $option    生成缩略图参数
 * @param  integer $interlace 启用隔行扫描
 * @return void
 */
	public function thumbFixed($source, $option = array(), $interlace = 1) {
		// 原始图片信息
		$src = $this->getImageInfo($source);
		if ($src === false) {
			return false;
		}
		// 缩略图扩展名获取
		$ext = $option['ext'] !== false ? $option['ext'] : $src['ext'];
		$rsWidth = $option['width'] / $src['width'];
		$rsHeight = $option['height'] / $src['height'];
		// 计算缩放比例
		$scale = max($rsWidth, $rsHeight);
		// 判断原图和缩略图比例 如原图宽于缩略图则裁掉两边 反之
		if ($rsWidth > $rsHeight) {
			$srcX = 0;
			$srcY = ($src['height'] - $option['height'] / $scale) / 2;
			$cutHeight = $option['height'] / $scale;
			$cutWidth = $src['width'];
		} else {
			$srcY = 0;
			$srcX = ($src['width'] - $option['width'] / $scale) / 2;
			$cutWidth = $option['width'] / $scale;
			$cutHeight = $src['height'];
		}

		// 图像标识符
		$im = $this->__switchCreateImageFunc($source, $src['mime']);
		if (!$im) {
			return false;
		}
		// 创建缩略图
		if ($ext != 'gif' && function_exists('imagecreatetruecolor')) {
			$thumb = imagecreatetruecolor($option['width'], $option['height']);
		} else {
			$thumb = imagecreate($option['width'], $option['height']);
		}

		// 复制图片
		if (function_exists('ImageCopyResampled')) {
			imagecopyresampled($thumb, $im, 0, 0, $srcX, $srcY, $option['width'], $option['height'], $cutWidth, $cutHeight);
		} else {
			imagecopyresized($thumb, $im, 0, 0, $srcX, $srcY, $option['width'], $option['height'], $cutWidth, $cutHeight);
		}
		if ('gif' == $ext || 'png' == $ext) {
			// imagealphablending($thumb, false);//取消默认的混色模式
			// imagesavealpha($thumb,true);//设定保存完整的 alpha 通道信息
			// 指派一个绿色
			$backgroundColor = imagecolorallocate($thumb, 0, 255, 0);
			// 设置为透明色，若注释掉该行则输出绿色的图
			imagecolortransparent($thumb, $backgroundColor);
		}

		// 对jpeg图形设置隔行扫描
		if ('jpg' == $ext || 'jpeg' == $ext) {
			imageinterlace($thumb, $interlace);
		}

		// 生成图片
		$savePath = $option['save_dir'] . $option['save_name'] . '.' . $ext;
		$this->__saveImage($thumb, $savePath, $ext);
		imagedestroy($thumb);
		imagedestroy($im);
		return true;
	}

/**
 * 设置制作缩略图的标识符
 * 
 * @param  $ext   $source 原始图片路径
 * @param  string $ext    扩展名
 * @return object         图像标识符
 */
	private function __switchCreateImageFunc($source, $mime = 'image/jpeg') {
		if ($mime == 'image/jpeg') {
			return imagecreatefromjpeg($source);
		} elseif ($mime == 'image/gif') {
			return imagecreatefromgif($source);
		} elseif ($mime == 'image/png') {
			return imagecreatefrompng($source);
		}
		return false;
	}

/**
 * 保存缩略图
 * @param  object $thumb    缩略图对象
 * @param  string $savePath 保存路径
 * @param  string $ext      扩展名
 * @return boolean
 */
	private function __saveImage($thumb, $savePath, $ext = 'jpg') {
		switch ($ext) {
			case 'jpg':
			case 'jpeg':
				return imagejpeg($thumb, $savePath);
			case 'gif':
				return imagegif($thumb, $savePath);
			case 'png':
				return imagepng($thumb, $savePath);
		}
		return false;
	}

/**
 * 获取水印所在坐标
 * 
 * @param  integer $position      数字1-9
 * @param  array   $sourceInfo    原图信息
 * @param  array   $watermarkInfo 水印图片信息
 * @return array
 */
	private function __getWatermarkPosition($position = 1, $sourceInfo = array(), $watermarkInfo = array()) {
		// 默认左上角
		$pos = array('x' => 0, 'y' => 0);
		// 垂直居中
		$halfY = ($sourceInfo['height'] - $watermarkInfo['height']) / 2;
		// 水平居中
		$halfX = ($sourceInfo['width'] - $watermarkInfo['width']) / 2;
		// 居下坐标
		$bottom = $sourceInfo['height'] - $watermarkInfo['height'];
		// 居右坐标
		$right = $sourceInfo['width'] - $watermarkInfo['width'];

		switch ($position) {
			case 1:
				// 左上角 默认坐标
				break;
			case 2:
				// 左中
				$pos['y'] = $halfY;
				break;
			case 3:
				// 左下
				$pos['y'] = $bottom;
				break;
			case 4:
				// 中上
				$pos['x'] = $halfX;
				break;
			case 5:
				// 中中
				$pos['x'] = $halfX;
				$pos['y'] = $halfY;
				break;
			case 6:
				// 中下
				$pos['x'] = $halfX;
				$pos['y'] = $bottom;
				break;
			case 7:
				// 右上
				$pos['x'] = $right;
				break;
			case 8:
				// 右中
				$pos['x'] = $right;
				$pos['y'] = $halfY;
				break;
			case 9:
				// 右下
				$pos['x'] = $right;
				$pos['y'] = $bottom;
				break;
		}
		return $pos;
	}
}
