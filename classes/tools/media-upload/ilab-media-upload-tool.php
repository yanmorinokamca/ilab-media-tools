<?php

// Copyright (c) 2016 Interfacelab LLC. All rights reserved.
//
// Released under the GPLv3 license
// http://www.gnu.org/licenses/gpl-3.0.html
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************

if (!defined('ABSPATH')) { header('Location: /'); die; }

require_once(ILAB_CLASSES_DIR.'/ilab-media-tool-base.php');

/**
 * Class ILabMediaUploadTool
 *
 * Video Tool.
 */
class ILabMediaUploadTool extends ILabMediaToolBase {
	public function __construct($toolName, $toolInfo, $toolManager) {
		parent::__construct($toolName, $toolInfo, $toolManager);

		if (is_admin()) {
			$this->setupAdmin();
		}
	}

	public function enabled() {
		$penabled = parent::enabled();

		if (!$penabled) {
			return false;
		}

		$s3Tool = $this->toolManager->tools['s3'];
		$enabled = $s3Tool->enabled();
		if (!$enabled)
			return false;

		return !$s3Tool->hasCustomEndPoint();
	}

	public function setupAdmin() {
		add_action( 'admin_enqueue_scripts', function(){
			wp_enqueue_script( 'wp-util' );
			wp_enqueue_style ( 'ilab-media-upload-css', ILAB_PUB_CSS_URL . '/ilab-media-upload.min.css' );
			wp_enqueue_script ( 'ilab-media-upload-js', ILAB_PUB_JS_URL. '/ilab-media-upload.js', ['jquery', 'wp-util'], false, true );
		});

			add_action('admin_menu', function(){
				if (current_user_can('upload_files')) {
					if ($this->enabled()) {
						add_media_page('Cloud Upload', 'Cloud Upload', 'upload_files', 'media-cloud-upload', [
							$this,
							'renderSettings'
						]);
					}
				}
			});

			add_action('wp_ajax_ilab_upload_prepare',function(){
				$this->prepareUpload();
			});

			add_action('wp_ajax_ilab_upload_import_s3_file',function(){
				$this->importS3File();
			});

			add_action('wp_ajax_ilab_upload_attachment_info',function(){
				$postId = $_POST['postId'];

				json_response(wp_prepare_attachment_for_js($postId));
			});

			add_filter('media_upload_tabs', function($tabs){
				if (current_user_can('upload_files')) {
					$tabs = array_merge(['ilab_cloud_upload' => 'Cloud Upload'], $tabs);
				}

				return $tabs;
			});

			add_action('media_upload_ilab_cloud_upload', function(){
				wp_iframe([$this,'renderInsertSettings']);
			});
	}

	private function importS3File() {
		if (!current_user_can('upload_files')) {
			json_response(["status" => "error", "message" => "Current user can't upload."]);
		}

		$key = $_POST['key'];
		if (empty($key)) {
			json_response(['status'=>'error', 'message'=>'Missing key.']);
		}

		/** @var ILabMediaS3Tool $s3Tool */
		$s3Tool = $this->toolManager->tools['s3'];

		/** @var \ILAB_Aws\S3\S3MultiRegionClient $s3 */
		$s3 = $s3Tool->s3Client();

		try {
			$result = $s3->headObject([
				                          'Bucket' => $s3Tool->s3Bucket(),
				                          'Key' => $key
			                          ]);
		} catch (Exception $ex) {
			error_log($ex->getMessage());
			json_response(['status'=>'error', 'message'=>'File does not exist on S3.']);
		}

		$type = $result->get('ContentType');

		$unknownMimes=[
			'application/octet-stream',
		    'application/binary',
		    'unknown/unknown'
		];

		if (!empty($type) && !in_array($type, $unknownMimes)) {
			if (strpos($type, 'image/')===0) {
				$result = $s3Tool->importImageAttachmentFromS3($key);
				if ($result) {
					json_response(['status'=>'success', 'data'=>$result]);
				} else {
					json_response(['status'=>'error', 'message'=>'Error importing S3 file into WordPress.']);
				}
			} else {
				json_response(['status'=>'error', 'message'=>'Unknown type.', 'type'=>$type]);
			}
		} else {
			json_response(['status'=>'error', 'message'=>'Unknown type.', 'type'=>$type]);
		}
	}

	private function prepareUpload() {
		if (!current_user_can('upload_files')) {
			json_response(["status" => "error", "message" => "Current user can't upload."]);
		}

		$filename = $_POST['filename'];
		$s3Tool = $this->toolManager->tools['s3'];
		$result = $s3Tool->uploadUrlForFile($filename);

		if (!empty($result)) {
			$po = $result['postObject'];
			$res = [
				'status'=>'ready',
				'key' => $result['key'],
				'attr' => $po->getFormAttributes(),
				'inputs'=>$po->getFormInputs(),
				'cacheControl' => $result['CacheControl'],
				'expires' => $result['Expires']
			];

			json_response($res);
		}

		json_response(['status'=>'error']);
	}
	/**
	 * Render settings.
	 */
	protected function doRenderSettings($insertMode) {
		/** @var ILabMediaImgixTool $imgixTool */
		$imgixTool = $this->toolManager->tools['imgix'];

		/** @var ILabMediaS3Tool $s3Tool */
		$s3Tool = $this->toolManager->tools['s3'];

		$mtypes = array_values(get_allowed_mime_types(get_current_user_id()));
		$mtypes[] = 'image/psd';

		$result = ILabMediaToolView::render_view('upload/ilab-media-upload.php',[
			'title'=>$this->toolInfo['title'],
			'group'=>$this->options_group,
			'page'=>$this->options_page,
			'imgixEnabled' => $this->toolManager->toolEnabled('imgix'),
			'videoEnabled' => $this->toolManager->toolEnabled('video'),
			'altFormats' => ($this->toolManager->toolEnabled('imgix') && $imgixTool->alternativeFormatsEnabled()),
			'docUploads' => $s3Tool->documentUploadsEnabled(),
			'insertMode' => $insertMode,
			'allowedMimes' => $mtypes
		]);

		echo $result;
	}

	/**
	 * Render settings.
	 */
	public function renderSettings() {
		$this->doRenderSettings(false);
	}

	/**
	 * Render settings.
	 */
	public function renderInsertSettings() {
		$this->doRenderSettings(true);
	}
}