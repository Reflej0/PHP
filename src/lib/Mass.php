<?php
namespace Leo\lib;
use Aws\S3\S3Client;

class Mass extends Uploader{
	private $bucket;
	private $tempFile;
	private $client;

	private $id;

	public $combine = true;
	// public $batch_size = 1024 * 1024 * 100;
	public $batch_size = 104857600;
	// public $record_size = 1024 * 1024 * 5;
	public $record_size = 5242880;
	public $max_records = 20;
	// public $duration = 60 * 60* 60;
	public $duration = 216000;

	private $opts;
	private $uploader;

	public function __construct($id, $config, $uploader, $opts = []) {
		ini_set('memory_limit', '500M');
		$this->id = $id;

		$this->opts = array_merge([
			'tmpdir'=>isset($opts['tmpdir'])?$opts['tmpdir']:sys_get_temp_dir()
		], $opts);

		$this->bucket = $config['leosdk']['s3'];
		$this->uploader = $uploader;

		$this->client = new S3Client([
			"version"   => "2006-03-01",
			'profile'   => $config['leoaws']['profile'],
			"region"    => $config['leoaws']['region'],
			 'http'     => [
		        'verify' => false
		    ]
		]);
	}

	public function sendRecords($batch) {
		// if we haven't created a temp file, create one now
		if (empty($this->tempFile)) {
			$this->tempFile = \tempnam($this->opts['tmpdir'], 'leo');
			$this->fhandle = gzopen($this->tempFile, 'wb6');
		}

		$correlation = "";
		foreach($batch['records'] as $record) {
			gzwrite($this->fhandle, $record['data']);
		}
		return [
				"success"=>true,
				"correlation"=>$correlation
			];
	}

	public function end() {
		if (empty($this->fhandle)) {
			$this->uploader->end();
			return;
		}

		gzclose($this->fhandle);

		$handler = fopen($this->tempFile,'r');

		$key = "bus_v2/{$this->id}/" . Utils::milliseconds() . ".gz";
		$result = $this->client->putObject([
			'Body'=> $handler,
			'Bucket'=> $this->bucket,
			'Key'=> $key
		]);

		fclose($handler);

		Utils::log($this->tempFile);

		// if we have an ObjectURL, it was successful. Remove the temp file.
		if (!empty($result['ObjectURL'])) {
			unlink($this->tempFile);
			Utils::log("Temp file ({$this->tempFile}) uploaded to S3 and cleaned up.");
		} else {
			throw new \Exception('Unable to upload ' . $this->tempFile . ' to S3');
		}

		$this->uploader->end();
		return;
	}
}