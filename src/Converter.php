<?php
declare(strict_types=1);

namespace MarcinOrlowski\ResponseBuilder;

/**
 * Laravel API Response Builder
 *
 * @package   MarcinOrlowski\ResponseBuilder
 *
 * @author    Marcin Orlowski <mail (#) marcinorlowski (.) com>
 * @copyright 2016-2019 Marcin Orlowski
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/MarcinOrlowski/laravel-api-response-builder
 */

use Illuminate\Support\Facades\Config;


/**
 * Data converter
 */
class Converter
{
	/**
	 * @var array|null
	 */
	protected $classes;

	public function __construct()
	{
		$classes = Config::get(ResponseBuilder::CONF_KEY_CLASSES) ?? [];
		if (!is_array($classes)) {
			throw new \RuntimeException(
				sprintf('CONFIG: "classes" mapping must be an array (%s given)', gettype($classes)));
		}

		$this->classes = $classes;
	}

	/**
	 * Checks if we have "classes" mapping configured for $data object class.
	 * Returns @true if there's valid config for this class.
	 *
	 * @param object $data Object to check mapping for.
	 *
	 * @return array
	 *
	 * @throws \RuntimeException if there's no config "classes" mapping entry
	 *                           for this object configured.
	 */
	protected function getClassMappingConfigOrThrow(object $data): array
	{
		$result = null;

		// check for exact class name match...
		$cls = get_class($data);
		if (array_key_exists($cls, $this->classes)) {
			$result = $this->classes[ $cls ];
		} else {
			// no exact match, then lets try with `instanceof`
			foreach ($this->classes as $class_name => $params) {
				if ($cls instanceof $class_name) {
					$result = $this->classes[ $cls ];
					break;
				}
			}
		}

		if ($result === null) {
			throw new \InvalidArgumentException(sprintf('No data conversion mapping configured for "%s" class.', $cls));
		}

		return $result;
	}

	/** We need to prepare t */
	public function convert($data = null): ?array
	{
		if (is_object($data)) {
			$cfg = $this->getClassMappingConfigOrThrow($data);
			$data = [$cfg[ ResponseBuilder::KEY_KEY ] => $data->{$cfg[ ResponseBuilder::KEY_METHOD ]}()];
		} else {
			if (!is_array($data) && $data !== null) {
				throw new \InvalidArgumentException(
					sprintf('Invalid payload data. Must be null, array or class with mapping ("%s" given).', gettype($data)));
			}
		}

		return $this->convertArray($data);
	}

	/**
	 * Recursively walks $data array and converts all known objects if found. Note
	 * $data array is passed by reference so source $data array may be modified.
	 *
	 * @param array|null $data array to recursively convert known elements of
	 *
	 * @return array|null
	 */
	protected function convertArray(array $data = null): ?array
	{
		if ($data === null) {
			return null;
		}

		if (!is_array($data) && !is_object($data)) {
			throw new \InvalidArgumentException(
				sprintf('Invalid payload data. Must be null, array or class with mapping ("%s" given).', gettype($data)));
		}

		if (is_object($data)) {
			$cfg = $this->getClassMappingConfigOrThrow($data);

			return [$cfg[ ResponseBuilder::KEY_KEY ] => $data->{$cfg[ ResponseBuilder::KEY_METHOD ]}()];
		}

		// This is to ensure that we either have array with user provided keys i.e. ['foo'=>'bar'], which will then
		// be turned into JSON object or array without user specified keys (['bar']) which we would return as JSON
		// array. But you can't mix these two as the final JSON would not produce predictable results.
		$user_keys_cnt = 0;
		$builtin_keys_cnt = 0;
		foreach ($data as $key => $val) {
			if (is_int($key)) {
				$builtin_keys_cnt++;
			} elseif (is_string($key)) {
				$user_keys_cnt++;
			} else {
				throw new \RuntimeException('Invalid data array. Array keys must either use strings as keys, or not use user provide keys.');
			}

			if (($user_keys_cnt > 0) && ($builtin_keys_cnt > 0)) {
				throw new \RuntimeException(
					'Invalid data array. Either set own keys for all the items or do not specify any keys at all. ' .
					'Arrays with mixed keys are not supported by design.');
			}
		}

		foreach ($data as $key => $val) {
			if (is_array($val)) {
				foreach ($val as $val_key => $val_val) {
//					if (is_object($val_val) && (!is_string($val_key))) {
//						throw new \InvalidArgumentException(
//							sprintf('Invalid payload data. Must be null, array or object ("%s" given).', gettype($data)));
//					}
				}
				$data[ $key ] = $this->convertArray($val);
			} elseif (is_object($val)) {
				$cls = get_class($val);
				$cfg = $this->getClassMappingConfigOrThrow($val);
				if (array_key_exists($cls, $this->classes)) {
					$conversion_method = $this->classes[ $cls ][ ResponseBuilder::KEY_METHOD ];
					$converted_data = $val->$conversion_method();
//							$data = [$this->classes[ $cls ][ ResponseBuilder::KEY_KEY ] => $converted_data];
					$data[ $key ] = $converted_data;
				}
			}
		}

		return $data;
	}
}
