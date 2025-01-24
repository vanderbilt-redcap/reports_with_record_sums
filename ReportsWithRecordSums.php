<?php

namespace Vanderbilt\ReportsWithRecordSums;

use ExternalModules\AbstractExternalModule;
use Twig\TwigFunction;
use ExternalModules\ExternalModules;
use REDCap;

const REPORT_NAME = "report-name";
const COL_VALUE = "column-value";
const HEADER_VALUE = "header-value";

class ReportsWithRecordSums extends AbstractExternalModule
{
	private mixed $reportSettings;

	public function buildReportTable($project_id, $report_index) {
		if (!is_numeric($report_index)) {
			return [];
		}

		$tableSettings = $this->getReportSettings($project_id, $report_index);
		$returnArray = ['name' => $tableSettings[REPORT_NAME], 'headers' => $tableSettings[HEADER_VALUE]];
		$tableData = \REDCap::getData([
			'project_id' => $this->getProjectId(),
			'return_format' => 'array',
		]);

		foreach ($tableData as $record => $rowData) {
			$returnArray['rows'][$record] = $this->processRecordColumns($record, $rowData, $tableSettings[COL_VALUE]);
		}

		return $returnArray;
	}

	public function getReportSettings($project_id, $report_index): array {
		if (!isset($this->reportSettings[$report_index])) {
			$this->loadAllReportSettings($project_id);
		}

		return $this->reportSettings[$report_index] ?? [];
	}

	public function loadAllReportSettings($project_id): void {
		$reports = $this->getProjectSetting(REPORT_NAME, $project_id);
		$columns = $this->getProjectSetting(COL_VALUE, $project_id);
		$headers = $this->getProjectSetting(HEADER_VALUE, $project_id);

		foreach ($reports as $index => $report) {
			$this->reportSettings[$index][REPORT_NAME] = $report;
			if (isset($columns[$index]) && isset($headers[$index])) {
				$this->reportSettings[$index][COL_VALUE] = $columns[$index];
				$this->reportSettings[$index][HEADER_VALUE] = $headers[$index];
			}
		}
	}

	public function getAllReportNames($project_id) {
		return $this->getProjectSetting(REPORT_NAME, $project_id);
	}

	public function replaceColumnReferences(array $columns): array {
		$errors = $colMatches = [];
		$getColumnRefs = function ($index) use ($columns) {
			$returnArray = ['results' => $columns[$index], 'errors' => []];
			$matches = [];
			$colNum = $index + 1;

			do {
				preg_match_all('/:col_(\d+):/', $returnArray['results'], $matches);

				if (!empty($matches[1])) {
					foreach ($matches[1] as $mIndex => $match) {
						if ((int)$match == (int)$colNum) {
							$returnArray['errors'][] = "Column $index references itself.";
						} elseif (!isset($columns[$match])) {
							$returnArray['errors'][] = "Column $index references another column that doesn't exist.";
						} else {
							$returnArray['results'] = str_replace(":col_$match:", $columns[$mIndex], $returnArray['results']);
						}
					}
				}
			} while ((empty($returnArray['errors']) && !empty($matches[1])));

			return $returnArray;
		};

		foreach ($columns as $index => $column) {
			$colMatches[$index] = $getColumnRefs($index);
			if (!empty($colMatches[$index]['errors'])) {
				$errors[] = $colMatches[$index]['errors'];
			} elseif (!empty($colMatches[$index]['results'])) {
				$columns[$index] = $colMatches[$index]['results'];
			}
		}

		return [$columns, $errors];
	}

	public function replaceSpecialTags($recordData, $columns) {
		$addCount = function ($data) {
			if (is_numeric($data)) {
				return $data;
			}
			return 0;
		};

		foreach ($columns as $cIndex => $column) {
			preg_match_all('/:(.*)\[(.*)\]:/', $column, $matches);
			if (empty($matches[1]) || empty($matches[2])) {
				continue;
			}

			foreach ($matches[1] as $mIndex => $match) {
				$fieldName = $matches[2][$mIndex];
				$currentCount = 0;

				switch ($match) {
					case "instance_sum":
						foreach ($recordData as $eventID => $eventData) {
							if ($eventID == 'repeat_instances') {
								foreach ($eventData as $subEvent => $subData) {
									foreach ($subData as $subInstrument => $instrumentData) {
										foreach ($instrumentData as $instance => $instanceData) {
											$currentCount += $addCount($instanceData[$fieldName]);
										}
									}
								}
							} else {
								$currentCount += $addCount($eventData[$fieldName]);
							}
						}

						$columns[$cIndex] = str_replace(":" . $match . "[" . $fieldName . "]:", $currentCount, $column);
						break;
					default:
						break;
				}
			}
		}
		return $columns;
	}

	public function processRecordColumns($record_id, array $recordData, array $columns): array {
		list($columns, $errors) = $this->replaceColumnReferences($columns);

		$columns = $this->replaceSpecialTags($recordData, $columns);

		array_walk($columns, function (&$val, $key) use ($record_id, $recordData) {
			$val = \Piping::replaceVariablesInLabel($val, $record_id, null, 1, [$record_id => $recordData], true, null, false);
			$val = $this->evaluate_math_string($val);
		});

		return $columns;
	}

	// Code originates from: https://github.com/samirkumardas/evaluate_math_string with the warning that it does not check for valid syntax
	// Modified slightly to not run this function on any string with letters in it
	public function evaluate_math_string($str) {
		$__eval = function ($str) use (&$__eval) {
			$error = false;
			$div_mul = false;
			$add_sub = false;
			$result = 0;
			// If this has letters we're not considering it basic math and ignore it
			if (preg_match("/[a-z]/i", $str)) {
				return $str;
			}
			$str = preg_replace('/[^\d.+\-*\/()]/i', '', $str);
			$str = rtrim(trim($str, '/*+'), '-');

			/* lets first tackle parentheses */
			if ((strpos($str, '(') !== false && strpos($str, ')') !== false)) {
				$regex = '/\(([\d.+\-*\/]+)\)/';
				preg_match($regex, $str, $matches);
				if (isset($matches[1])) {
					return $__eval(preg_replace($regex, $__eval($matches[1]), $str, 1));
				}
			}

			/* Remove unwanted parentheses */
			$str = str_replace(['(', ')'], '', $str);
			/* now division and multiplication */
			if ((strpos($str, '/') !== false || strpos($str, '*') !== false)) {
				$div_mul = true;
				$operators = ['*', '/'];
				while (!$error && $operators) {
					$operator = array_pop($operators);
					while ($operator && strpos($str, $operator) !== false) {
						if ($error) {
							break;
						}
						$regex = '/([\d.]+)\\' . $operator . '(\-?[\d.]+)/';
						preg_match($regex, $str, $matches);
						if (isset($matches[1]) && isset($matches[2])) {
							if ($operator == '+') {
								$result = (float)$matches[1] + (float)$matches[2];
							}
							if ($operator == '-') {
								$result = (float)$matches[1] - (float)$matches[2];
							}
							if ($operator == '*') {
								$result = (float)$matches[1] * (float)$matches[2];
							}
							if ($operator == '/') {
								if ((float)$matches[2]) {
									$result = (float)$matches[1] / (float)$matches[2];
								} else {
									$error = true;
								}
							}
							$str = preg_replace($regex, $result, $str, 1);
							$str = str_replace(['++', '--', '-+', '+-'], ['+', '+', '-', '-'], $str);
						} else {
							$error = true;
						}
					}
				}
			}

			if (!$error && (strpos($str, '+') !== false || strpos($str, '-') !== false)) {
				//tackle duble negation
				$str = str_replace('--', '+', $str);
				$add_sub = true;
				preg_match_all('/([\d\.]+|[\+\-])/', $str, $matches);
				if (isset($matches[0])) {
					$result = 0;
					$operator = '+';
					$tokens = $matches[0];
					$count = count($tokens);
					for ($i = 0; $i < $count; $i++) {
						if ($tokens[$i] == '+' || $tokens[$i] == '-') {
							$operator = $tokens[$i];
						} else {
							$result = ($operator == '+') ? ($result + (float)$tokens[$i]) : ($result - (float)$tokens[$i]);
						}
					}
				}
			}
			if (!$error && !$div_mul && !$add_sub) {
				$result = (float)$str;
			}
			return $error ? 0 : $result;
		};
		return $__eval($str);
	}

	public function loadDataReportTwig($project_id, $report_index) {
		$reportList = $this->getAllReportNames($project_id);
		$reportData = $this->buildReportTable($project_id, $report_index);

		return $this->getTwig()->render('data_report.html.twig', [
			'report_list' => $reportList,
			'report_data' => $reportData
		]);
	}

	public function loadTwigExtensions(): void {
		$this->initializeTwig();
		$this->getTwig()->addFunction(new TwigFunction('dataReport', function ($report_index, $project_id) {
			$urlStr = $this->getUrl('data_report.php') . '?report_index=' . $report_index . "&pid=" . $project_id;
			return $urlStr;
		}));

		$this->getTwig()->addFunction(new TwigFunction('loadJSBS', function () {
			return $this->framework->loadBootstrap() . $this->framework->loadREDCapJS();
		}));
	}
}
