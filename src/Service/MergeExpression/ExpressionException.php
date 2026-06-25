<?php

declare(strict_types=1);

namespace MSpaceMedia\Newsletter\Service\MergeExpression;

use RuntimeException;

/**
 * Raised for any malformed expression (parse error) or disallowed/invalid
 * traversal at evaluation time. Carries an editor-facing message — the merge
 * field builder surfaces it directly in the CMS.
 */
class ExpressionException extends RuntimeException
{
}
