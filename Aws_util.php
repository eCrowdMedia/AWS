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
}
// END Aws_util Class

/* End of file Aws_util.php */