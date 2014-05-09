<?php
/**
 * 文件上传组件
 * 
 * @author Will Lee <im.will.lee@gmail.com>
 * @copyright LeeCMS
 * @since PHP 5.3.X CakePHP 2.X
 */

App::uses('Component', 'Controller');

class UploadFileComponent extends Component {

/**
 * 上传类型定义所有
 * 
 * @var integer
 */
	const UPLOAD_TYPE_ALL = 0;

/**
 * 上传类型定义图片
 * 
 * @var integer
 */
	const UPLOAD_TYPE_IMAGE = 1;

/**
 * 创建文件夹访问权限
 * 
 * @var integer
 */
	const CREATE_MODE = 0777;

/**
 * 导入component
 * 
 * @var array
 */
	public $components = array('Image');

/**
 * 允许上传的mime类型
 * 
 * @var array
 */
	protected $_allowedMime = array(
		// 图片类型
		'jpg' => array('image/jpg', 'image/jpeg', 'image/pjpeg'),
		'gif' => array('image/gif'),
		'png' => array('image/png', 'image/x-png'),

		// 压缩文件
		'zip' => array('application/x-zip', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'),
		'rar' => array('application/x-rar-compressed', 'application/octet-stream'),

		// 文档
		'txt' => array('text/plain'),
		'pdf' => array('application/pdf'),
		'doc' => array('application/msword'),
		'xls' => array('application/vnd.ms-excel'),
		'ppt' => array('application/vnd.ms-powerpoint'),
		'swf' => array('application/x-shockwave-flash')
	);

/**
 * 保存文件名规则
 * 
 * @var array
 */
	protected $_saveRule = array('uniqid', 'guid', 'date', 'time');

/**
 * 上传参数配置
 * 
 * @var array
 */
	protected $_config = array(
		// 上传保存目录
		'save_dir' => DEFAULT_UPLOAD_PATH,
		// 自动创建子目录,开启后将会在上传路径下自动创建yyyy/mm/dd目录
		'sub_dir' => false,
		// 文件命名方式 uniqid,guid,date,time
		// 使用date和time规则时 同一秒内上传可能会造成重名问题
		'save_rule' => false,
		// 文件保存名称(不包含扩展名) 如果开启save_rule 此项将不生效
		// 默认使用上传文件名作为保存文件名
		'save_name' => false,
		// 允许上传文件类型 设置-1允许所有文件上传 其他为数组配置array('jpg','gif','png')
		'allow_exts' => -1,
		// 上传文件大小限制 默认为2M -1为不限制
		'limit_size' => DEFAULT_UPLOAD_SIZE,
		// 是否生成缩略图
		'thumb' => false,
		// 生成缩略图类型 thumb 等比缩放 thumbFixed 绝对缩放
		'thumb_engine' => 'thumb',
		// 缩略图保存规则 支持数组
		// save_dir 缩略图保存目录, save_name 缩略图保存名称
		// ext 扩展名不设置则用原图片, width 生成宽度, height 生成高度
		'thumb_save_rule' => array(
			// array('save_dir' => DEFAULT_UPLOAD_PATH, 'save_name' => '320x240','ext' => false, 'width' => 320, 'height' => 240)
		),
		// 生成缩略图后是否删除原始图片
		'thumb_remove_origin' => false,
		// 添加水印
		'watermark' => false,
		// 水印文件路径
		'watermark_src' => 'watermark.jpg',
		// 对齐位置 数字1-9
		'position' => 1,
		// 水印透明度
		'alpha' => 50,
		// 添加水印后保存路径 默认为替换原文件
		'watermark_save_path' => false
	);

/**
 * 上传类型限制
 * 
 * @var array
 */
	public $uploadLimitType = array();

/**
 * 错误信息保存
 * 
 * @var boolean
 */
	public $error = false;

/**
 * 组件初始化
 * 
 * @param  Controller $controller 控制器
 * @return void
 */
	public function initialize(Controller $controller) {
		$this->Controller = $controller;
		$this->uploadLimitType = array(
			self:: UPLOAD_TYPE_IMAGE => array('jpg', 'gif', 'png')
		);
	}

/**
 * 上传设置
 * 
 * @param array $config 设置选项
 */
	public function setConfig($config = array()) {
		$this->_config = am($this->_config, $config);
	}

/**
 * 上传文件
 * 
 * @param  array $file   文件数组
 * @return array
 */
	public function upload($file) {
		if (empty($file['tmp_name']) || empty($file['name'])) {
			$this->error = '没有选择上传文件。';
			return false;
		}
		$info = pathinfo($file['name']);
		if (empty($info['extension']) || empty($info['filename'])) {
			$this->error = '上传文件出错，请确保文件命名正确。';
			return false;
		}
		// 文件扩展名和文件名
		$file['ext'] = strtolower($info['extension']);
		$file['filename'] = $info['filename'];
		// 检查上传文件
		if (!$this->_checkUploadFile($file)) {
			return false;
		}
		// 取得上传文件目录
		$file['save_dir'] = $this->_getSavePath();
		if ($file['save_dir'] === false) {
			return false;
		}
		// 取得保存的文件名
		$file['save_name'] = $this->_getSaveFileName($file);
		// 上传文件
		if (!move_uploaded_file($file['tmp_name'], $file['save_dir'] . $file['save_name'])) {
			$this->error = '文件保存失败。';
			if (file_exists($file['tmp_name'])) {
				unlink($file['tmp_name']);
			}
			return false;
		}
		// 添加水印
		if ($this->_config['watermark'] === true) {
			if (!$this->_makeWatermark($file)) {
				return false;
			}
		}

		// 缩略图制作
		if ($this->_config['thumb'] === true) {
			if (!$this->_makeThumb($file)) {
				return false;
			}
		}
		unset($file['tmp_name'], $file['error']);
		return $file;
	}

/**
 * 检查上传文件
 * 
 * @param  array $file  暂存文件信息
 * @return boolean
 */
	protected function _checkUploadFile($file) {
		if ($file['error'] !== 0) {
			$this->_error($file['error']);
			return false;
		}
		// 文件大小判断
		if ($this->_config['limit_size'] !== -1) {
			if ($file['size'] > $this->_config['limit_size']) {
				$this->error = '上传文件大小超出限制:' . size_format($this->_config['limit_size']) . '。';
				return false;
			}
		}
		// 允许类型判断
		if ($this->_config['allow_exts'] !== -1) {
			if (!in_array($file['ext'], $this->_config['allow_exts'])) {
				$this->error = '只允许上传:' . implode(',', $this->_config['allow_exts']) . '类型的文件。';
				return false;
			}
		}
		// MIME类型判断
		if (!$this->_checkFileMime($file)) {
			$this->error = '上传文件MIME类型不在允许类型范围之内。';
			return false;
		}
		// 是否非法提交
		if (!is_uploaded_file($file['tmp_name'])) {
			$this->error = '非法上传文件。';
			return false;
		}
		return true;
	}

/**
 * 文件mime类型判断
 * 
 * @param  array $file  暂存文件信息
 * @return boolean
 */
	protected function _checkFileMime($file) {
		$mime = $file['type'];
		// 如果开启了php_fileinfo扩展 取得文件真实的mime
		if (function_exists('finfo_file')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime = finfo_file($finfo, $file['tmp_name']);
			finfo_close($finfo);
		}
		if (!isset($this->_allowedMime[$file['ext']]) || !in_array($mime, $this->_allowedMime[$file['ext']])) {
			return false;
		}
		return true;
	}

/**
 * 检查上传目录 不存在则自动创建
 * 
 * @return string or boolean
 */
	protected function _getSavePath() {
		$saveDir = $this->_config['save_dir'];
		// 开启自动创建子目录
		if ($this->_config['sub_dir'] === true) {
			$saveDir .= date('Y') . DS . date('m') . DS . date('d') . DS;
		}
		if ($this->__checkDir($saveDir)) {
			return $saveDir;
		} else {
			return false;
		}
	}

/**
 * 获取保存文件名称 带扩展名
 * 
 * @param  array $file  暂存文件信息
 * @return string or boolean
 */
	protected function _getSaveFileName($file) {
		if ($this->_config['save_rule'] !== false && in_array($this->_config['save_rule'], $this->_saveRule)) {
			return $this->_getRuleName() . '.' . $file['ext'];
		}
		if ($this->_config['save_name'] !== false) {
			return $file['filename'] . '.' . $file['ext'];
		} else {
			return $this->_config['save_name'] . '.' . $file['ext'];
		}
	}

/**
 * 获取指定规则的文件名
 * 'uniqid', 'guid', 'date'
 * @return string
 */
	protected function _getRuleName() {
		$filename = false;
		switch ($this->_config['save_rule']) {
			case 'uniqid':
				$filename = uniqid();
				break;
			case 'guid':
				$filename = guid();
				break;
			case 'date':
				$filename = date('YmdHis');
				break;
			case 'time':
				$filename = time();
				break;
		}
		return $filename;
	}

/**
 * 取得错误代码信息
 * 
 * @param  integer $errorNo 错误代码
 * @return void
 */
	protected function _error($errorNo) {
		switch($errorNo) {
			case 1:
				$this->error = '上传的文件超过了 php.ini 中 upload_max_filesize 选项限制的值。';
				break;
			case 2:
				$this->error = '上传文件的大小超过了 HTML 表单中 MAX_FILE_SIZE 选项指定的值。';
				break;
			case 3:
				$this->error = '文件只有部分被上传。';
				break;
			case 4:
				$this->error = '没有文件被上传。';
				break;
			case 6:
				$this->error = '找不到临时文件夹。';
				break;
			case 7:
				$this->error = '文件写入失败。';
				break;
			case 8:
				$this->error = 'php扩展导致上传终止。';
				break;
			default:
				$this->error = '未知上传错误。';
		}
	}

/**
 * 检查目录是否存在 不存在自动创建
 * 
 * @param  string $saveDir [description]
 * @return boolean
 */
	private function __checkDir($saveDir) {
		if (!is_dir($saveDir)) {
			if (!mkdir($saveDir, self::CREATE_MODE, true)) {
				$this->error = '目录:' . $saveDir . '创建失败。';
				return false;
			}
		}
		if (!is_writeable($saveDir)) {
			$this->error = '目录:' . $saveDir . '没有写入权限。';
			return false;
		}
		return true;
	}

/**
 * 制作缩略图
 * 
 * @param  array $file  暂存文件信息
 * @return string or boolean
 */
	protected function _makeThumb($file) {
		if (empty($this->_config['thumb_save_rule']) && !is_array($this->_config['thumb_save_rule'])) {
			$this->error = '生成缩略图规则参数不存在。';
			return false;
		}
		$source = $file['save_dir'] . $file['save_name'];
		foreach ($this->_config['thumb_save_rule'] as $option) {
			if (!$this->__checkDir($option['save_dir'])) {
				return false;
			}
			$thumb = false;
			if ($this->_config['thumb_engine'] == 'thumb') {
				$thumb = $this->Image->thumb($source, $option);
			} else {
				$thumb = $this->Image->thumbFixed($source, $option);
			}
			if (!$thumb) {
				$this->error = '制作缩略图失败。';
				return false;
			}
		}
		// 删除原来的图片
		if ($this->_config['thumb_remove_origin'] === true) {
			unset($source);
		}
		return true;
	}

/**
 * 添加水印
 * 
 * @param  array $file  暂存文件信息
 * @return string or boolean
 */
	protected function _makeWatermark($file) {
		$option = array(
			'position' => $this->_config['position'],
			'alpha' => $this->_config['alpha'],
			'save_path' => $this->_config['watermark_save_path']
		);
		if (!empty($option['save_path'])) {
			$dir = dirname($option['save_path']);
			if (!is_dir($dir)) {
				if (!$this->__checkDir($dir)) {
					return false;
				}
			}
		}
		$source = $file['save_dir'] . $file['save_name'];
		if (!$this->Image->watermark($source, $this->_config['watermark_src'], $option)) {
			$this->error = '水印添加失。';
			return false;
		}
		return true;
	}
}
