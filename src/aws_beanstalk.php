<?php
/**
 * Functions related to AWS Beanstalk (Amazon Web Services)
 */

namespace winternet\jensenfw2;

class aws_beanstalk {

	var $awsebsysteminitialized = null;

	/**
	 * Get an instance ID of an Elastic Beanstalk instance
	 *
	 * This works even in a CLI script run by cron
	 *
	 * @param string $name : Name of environment variable
	 */
	public function get_instance_id() {

		// Should we use `curl http://169.254.169.254/latest/dynamic/instance-identity/document` instead??

		if ($this->awsebsysteminitialized == null) {
			$this->load_awsebsysteminitialized();
		}

		if (!$this->awsebsysteminitialized['instance_id']) {
			core::error('Instance ID not found.', ['Contents' => $this->awsebsysteminitialized]);
		}

		return $this->awsebsysteminitialized['instance_id'];
	}

	public function get_instance_first_init_time() {
		if ($this->awsebsysteminitialized == null) {
			$this->load_awsebsysteminitialized();
		}

		if (!$this->awsebsysteminitialized['first_init_time']) {
			core::error('First initialization time not found.', ['Contents' => $this->awsebsysteminitialized]);
		}

		return $this->awsebsysteminitialized['first_init_time'];
	}

	/**
	 * Get an environment variable from an Elastic Beanstalk instance
	 *
	 * This works even in a CLI script run by cron
	 *
	 * @param string $name : Name of environment variable
	 */
	public function get_env($name) {

		if (array_key_exists($name, $_SERVER)) {
			return $_SERVER[$name];
		} elseif (getenv($name) !== false) {  //effective in case $name is has wrong upper/lower case
			return getenv($name);
		} else {
			// For some reason the environment variables EB_ENV is not available when running this via a cron job...!
			$file = '/etc/httpd/conf.d/aws_env.conf';

			if (!file_exists($file)) {
				core::error('Server does not seem to be an AWS Elastic Beanstalk instance.');
			}
			$awsenv = file_get_contents($file);

			if (preg_match('/\\b'. $name .'\\b "(.*)"/U', $awsenv, $match)) {
				return $match[1];
			} else {
				return false;
			}
		}
	}

	/**
	 * Get public URL for the current environment
	 *
	 * Must be run as root if $region or $environmentID is not provided.
	 *
	 * @return string : For example `http://myserver.eu-west-1.elasticbeanstalk.com/`
	 */
	public function get_environment_url($region = null, $environmentID = null) {
		// Source: https://serverfault.com/a/943320/340429
		// Similar: https://stackoverflow.com/questions/45907623/is-it-possible-to-get-elastic-beanstalk-address-from-within-its-ec2-instance
	
		if (!$region || !$environmentID) {
			$file = '/etc/elasticbeanstalk/.aws-eb-stack.properties';
			$props = file_get_contents($file);
			if (!$props) {
				core::system_error('Failed to read AWS Elastic Beanstalk stack properties.', ['Details' => 'Probably missing permission. Only root can read it.']);
			}
		}

		if (!$region) {
			if (preg_match("/^region=([a-z0-9\\-\\s]+)$/mi", $props, $match)) {
				$region = trim($match[1]);
			} else {
				core::system_error('Failed to find region in AWS Elastic Beanstalk stack properties.', ['Props' => $props]);
			}
		} else {
			$region = preg_replace("/[^a-z0-9\\-]/", '', $region);
		}

		if (!$environmentID) {
			if (preg_match("/^environment_id=([a-z0-9\\-\\s]+)$/mi", $props, $match)) {
				$environmentID = trim($match[1]);
			} else {
				core::system_error('Failed to find environment ID in AWS Elastic Beanstalk stack properties.', ['Props' => $props]);
			}
		} else {
			$environmentID = preg_replace("/[^a-z0-9\\-]/", '', $environmentID);
		}

		$cmd = 'aws elasticbeanstalk describe-environments --region '. $region .' --environment-id '. $environmentID .' --query "Environments[0].CNAME"';

		$output = []; $exitcode = 0;
		exec($cmd, $output, $exitcode);
		
		if ($exitcode != 0) {
			core::system_error('Getting environment URL resulted in exit code '. $exitcode .'.', ['Output' => $output]);
		}

		return trim(trim(implode('', $output)), '"');
	}



	private function load_awsebsysteminitialized() {
		$file = '/etc/elasticbeanstalk/.aws-eb-system-initialized';

		if (!file_exists($file)) {
			core::error('Server does not seem to be an AWS Elastic Beanstalk instance.');
		}

		$instance = json_decode(file_get_contents($file), true);

		if ($instance == null) {
			core::error('Server does not seem to be an AWS Elastic Beanstalk instance.', ['File content' => file_get_contents($file) ]);
		}

		$this->awsebsysteminitialized = $instance;
	}
}
