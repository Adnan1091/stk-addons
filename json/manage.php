<?php
/**
 * copyright 2014 Daniel Butum <danibutum at gmail dot com>
 *
 * This file is part of stkaddons
 *
 * stkaddons is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * stkaddons is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with stkaddons.  If not, see <http://www.gnu.org/licenses/>.
 */
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config.php");

if (!isset($_POST["action"]) || empty($_POST["action"]))
{
    exit_json_error("action param is not defined or is empty");
}

switch ($_POST["action"])
{
    case "add-role":
        $errors = Validate::ensureInput($_POST, ["role"]);
        if ($errors)
        {
            exit_json_error(implode("<br>", $errors));
        }

        try
        {
            AccessControl::addRole($_POST["role"]);
        }
        catch(AccessControlException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success("Role added");
        break;

    case "delete-role":
        $errors = Validate::ensureInput($_POST, ["role"]);
        if ($errors)
        {
            exit_json_error(implode("<br>", $errors));
        }

        try
        {
            AccessControl::deleteRole($_POST["role"]);
        }
        catch(AccessControlException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success("Role Deleted");
        break;

    case "edit-role": // edit a role permissions or maybe the role name in the future
        $errors = Validate::ensureInput($_POST, ["role", "permissions"]);
        if ($errors)
        {
            exit_json_error(implode("<br>", $errors));
        }
        if (!is_array($_POST["permissions"]))
        {
            exit_json_error("The permissions param is not an array");
        }

        try
        {
            AccessControl::setPermissions($_POST["role"], $_POST["permissions"]);
        }
        catch(AccessControlException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success("Permissions set successfully");
        break;

    case "get-role": // get the permission of a role
        $errors = Validate::ensureInput($_POST, ["role"]);
        if ($errors)
        {
            exit_json_error(implode("<br>", $errors));
        }

        if (!User::hasPermission(AccessControl::PERM_EDIT_PERMISSIONS))
        {
            exit_json_error("You do not have the necessary permission to get a role");
        }
        if (!AccessControl::isRole($_POST["role"]))
        {
            exit_json_error("The role is not valid");
        }

        exit_json_success("", ["permissions" => AccessControl::getPermissions($_POST["role"])]);
        break;

    default:
        exit_json_error(sprintf("action = %s is not recognized", h($_POST["action"])));
        break;
}