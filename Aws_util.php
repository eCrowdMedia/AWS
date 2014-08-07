<?php
/**
 * Aws_util Class
 *
 * @package     Readmoo
 * @subpackage  AWS
 * @category    Libraries
 * @author      Willy
 * @link        https://readmoo.com
 */
/*
{
	"input": [
		{
			"path: "task",
			"class": "import",
			"method": "explore",
			"parameters": array($json['key'])
		},
		{
			cli: ""
		},
		{
			cmd: ""
		}
	],
	"output": {
		cli / cmd
	}
}
*/
class Aws_util {
	private $_cli_base = FALSE;
	private static $_s3_protocol = 's3://';

	function __construct($config = array())
	{
		$this->_cli_base = isset($config['cli']) ? $config['cli'] : (BASEPATH . 'cli.php');
	}

	public function add_task($task)
	{
		if (is_string($task))
			$this->add_cmd($task);
		elseif (isset($task['cmd']))
			$this->add_cmd($task['cmd']);
		elseif (isset($task['cli']))
			$this->add_cli($task['cli']);
		elseif (is_array($task))
			foreach ($task as $key => $value)
				if (is_numeric($key)) $this->add_task($value);
		return $this;
	}

	public function add_cmd($cmd)
	{
		if (is_string($cmd))
			$this->_tasks[] = array(
				'cmd' => $cmd
			);
		elseif (is_array($cmd))
			foreach ($cmd as $key => $value)
				if (is_numeric($key) && is_string($value))
					$this->_tasks[] = array(
						'cmd' => $value
					);
		return $this;
	}

	public function add_cli($cli)
	{
		if (is_array($cli) && empty($cli['class'])) return;
		$this->_tasks[] = array(
			'cli' => $cli
		);
		return $this;
	}

	private function _cli_to_cmd($cli)
	{
		$command = $this->_cli_base;
		if (is_string($cli))
			$command .= ' ' . $cli;
		elseif (isset($cli['class']))
		{
			empty($cli['path']) OR $command .= ' ' . implode(' ', (array)$cli['path']);
			$command .= ' ' . $cli['class'];
			$command .= ' ' . ((empty($cli['method']) OR ! is_string($cli['method'])) ? 'index' : $cli['method']);
			if (isset($cli['parameters'])) $command .= ' ' . (is_array($cli['parameters']) ? implode(' ', $cli['parameters']) : $cli['parameters']);
		}
		else
			return FALSE;
		return $command;
	}

	public function get_combined_cmd($tasks = array())
	{
		foreach ($tasks as $mode => $task)
			$this->add_task($task);
		$cmds = array();
		foreach ($this->_tasks as $task)
		{
			if (isset($task['cmd']))
				$cmd = $task['cmd'];
			elseif (isset($task['cli']))
				$cmd = $this->_cli_to_cmd($task['cli']);
			else
				unset($cmd);
			if ( ! empty($cmd)) $cmds[] = $cmd;
		}
		return implode(' && ', $cmds);
	}

	public function clear_task()
	{
		$this->_tasks = array();
		return $this;
	}

	public function s3_sync($source, $target, $options = false)
	{
		$args = array();
		$mode = 'sync';
		if (is_array($options)) {
			foreach ($options as $key => $value) {
				switch (strtolower($key)) {
					case 'mode':
						if (in_array($value, array('sync', 'get', 'put'))) {
							$mode = $value;
						}
						if ($mode == 'put' &&
							(strpos($source, self::$_s3_protocol) === 0 OR ! file_exists($source))
						) {
							return false;
						}
						break;

					case 'recursive':
						if ($value) {
							$args[] = '-r';
						}
						break;

					case 'reducedredundancy':
						if ($value) {
							$args[] = '--rr';
						}
						break;

					case 'public':
						if ($value) {
							$args[] = '-P';
						}
						break;

					case 'force':
						if ($value) {
							$args[] = '-f';
						}
						break;

					case 'delete':
						if ($value) {
							$args[] = '--delete-removed';
						}
						break;

					default:
						# code...
						break;
				}
			}
		}

		$CI =& get_instance();
		$CI->config->load('cmd', true);
		$cmd = sprintf(
			'%s %s %s "%s" "%s"',
			$CI->config->item('cmd_s3cmd', 'cmd'),
			$mode,
			implode(' ', $args),
			$source,
			$target
		);
		$CI->load->add_package_path(config_item('common_package'));
		$CI->load->library('process_lib');
		$CI->load->remove_package_path(config_item('common_package'));
		return $CI->process_lib->execute($cmd);
	}

	public function s3_del($s3_key)
	{
		if (empty($s3_key) OR strpos($s3_key, self::$_s3_protocol) !== 0) {
			return false;
		}
		$CI =& get_instance();
		$CI->config->load('cmd', true);
		$CI->load->add_package_path(config_item('common_package'));
		$CI->load->library('process_lib');
		$CI->load->remove_package_path(config_item('common_package'));
		return $CI->process_lib->execute($CI->config->item('cmd_s3cmd', 'cmd') . ' del -r ' . $s3_key);
	}

	public function s3_key(array $params, $mode = 'ebook', $use_cf = false)
	{
		$CI =& get_instance();
		$CI->config->load('aws', true);
		$segments = array(
			self::$_s3_protocol . (
				$use_cf ?
					$CI->config->item('cf_bucket', 'aws') :
					$CI->config->item('s3_bucket', 'aws')
			),
			$mode
		);
		switch ($mode) {
			case 'ebook':
				// file
				if (isset($params['file'])) {
					$file = $params['file'];
					if (empty($file['manifestation_id']) OR
						empty($file['sn'])) {
						return false;
					}
					$segments[] = $file['manifestation_id'] % 1000;
					$segments[] = $file['manifestation_id'];
					$segments[] = $file['sn'];
					if (empty($file['version']) OR
						empty($file['setting'])) {
						break;
					}
					$setting = json_decode($file['setting'], true);
					if (isset($setting['revision'])) {
						$segments[] = $file['version'] . '_' . $file['revision'];
					}
				}
				// manifestataion
				elseif (isset($params['manifestation'])) {
					if (empty($params['manifestation']['sn'])) {
						return false;
					}
					$segments[] = $params['manifestation']['sn'] % 1000;
					$segments[] = $params['manifestation']['sn'];
				}
				break;

			case 'cover':
			case 'avatar':
			default:
				return false;
				break;
		}
		return implode('/', $segments) . '/';
	}
}
// END Aws_util Class

/* End of file Aws_util.php */
