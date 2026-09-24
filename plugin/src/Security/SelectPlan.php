<?php
/**
 * A checked SELECT built by TableGuard: SQL with placeholders only for values.
 *
 * @package Lab591\DevBridge
 */

declare(strict_types=1);

namespace Lab591\DevBridge\Security;

final class SelectPlan {

	/**
	 * @param string                                 $sql      SELECT with `%s` placeholders; LIMIT is one row more than $limit.
	 * @param list<string>                           $args     Values for the placeholders.
	 * @param list<string>                           $columns  Selected columns, in output order.
	 * @param list<string>                           $hidden   Sensitive columns of the table, never selected.
	 * @param array{key: string, value: string}|null $keyValue Key and value columns of a key-value table.
	 * @param int                                    $limit    Rows requested.
	 */
	public function __construct(
		public readonly string $sql,
		public readonly array $args,
		public readonly array $columns,
		public readonly array $hidden,
		public readonly ?array $keyValue,
		public readonly int $limit,
	) {
	}
}
