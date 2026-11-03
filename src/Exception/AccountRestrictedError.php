<?php

declare(strict_types=1);

namespace TeePay\Exception;

/**
 * 403 account_restricted - the account is restricted. Contact TeePay support; retrying will not help.
 */
class AccountRestrictedError extends PermissionError
{
}
