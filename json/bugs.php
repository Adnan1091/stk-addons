<?php
/**
 * copyright 2014 Daniel Butum <danibutum at gmail dot com>
 *
 * This file is part of stk-addons.
 *
 * stk-addons is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * stk-addons is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with stk-addons. If not, see <http://www.gnu.org/licenses/>.
 */
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config.php");

header("Content-Type: application/json");
if (empty($_POST["action"]))
{
    exit_json_error("action param is not defined or is empty");
}

// TODO make user answer captcha question when he spams the add/submit button
switch ($_POST["action"])
{
    case "add": // add bug
        if (Validate::ensureNotEmpty($_POST, ["addon-name", "bug-title", "bug-description"]))
        {
            exit_json_error(_h("One or more fields are empty"));
        }

        try
        {
            Bug::add(User::getLoggedId(), Addon::cleanId($_POST["addon-name"]), $_POST["bug-title"], $_POST["bug-description"]);
        }
        catch(BugException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success(_h("Bug report added"));
        break;

    case "add-comment": // add bug comment
        if (Validate::ensureNotEmpty($_POST, ["bug-comment-description", "bug-id"]))
        {
            exit_json_error(_h("One or more fields are empty"));
        }

        $comment_id = -1;
        try
        {
            $comment_id = Bug::addComment(User::getLoggedId(), (int)$_POST["bug-id"], $_POST["bug-comment-description"]);
        }
        catch(BugException $e)
        {
            exit_json_error($e->getMessage());
        }

        // send back to comment to the user
        $comment_data = Bug::getCommentData($comment_id);
        $tpl_comment = StkTemplate::get("bugs/view-comment.tpl")->assign(
            "comment",
            [
                "id"          => $comment_data["id"],
                "user_name"   => User::getLoggedUserName(),
                "date"        => $comment_data["date"],
                "description" => $comment_data["description"]
            ]
        )->assign("can_edit_comment", User::hasPermission(AccessControl::PERM_EDIT_BUGS));


        exit_json_success(_h("Comment added"), ["comment" => (string)$tpl_comment]);
        break;

    case "edit":
        if (Validate::ensureNotEmpty($_POST, ["bug-title-edit", "bug-description-edit", "bug-id"]))
        {
            exit_json_error(_h("One or more fields are empty"));
        }

        try
        {
            Bug::update((int)$_POST["bug-id"], $_POST["bug-title-edit"], $_POST["bug-description-edit"]);
        }
        catch(BugException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success(_h("Bug updated"));
        break;

    case "edit-comment":
        if (Validate::ensureNotEmpty($_POST, ["bug-comment-edit-description", "comment-id"]))
        {
            exit_json_error(_h("One or more fields are empty"));
        }

        try
        {
            Bug::updateComment((int)$_POST["comment-id"], $_POST["bug-comment-edit-description"]);
        }
        catch(BugException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success(_h("Bug comment updated"));
        break;

    case "close": // close a bug
        if (Validate::ensureNotEmpty($_POST, ["modal-close-reason", "bug-id"]))
        {
            exit_json_error(_h("One or more fields are empty"));
        }

        try
        {
            Bug::close((int)$_POST["bug-id"], $_POST["modal-close-reason"]);
        }
        catch(BugException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success(_h("Bug closed"));
        break;

    case "delete":
        if (Validate::ensureNotEmpty($_POST, ["bug-id"]))
        {
            exit_json_error(_h("One or more fields are empty"));
        }

        try
        {
            Bug::delete((int)$_POST["bug-id"]);
        }
        catch(DBException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success(_h("Bug deleted"));
        break;

    case "delete-comment": // delete a comment
        if (Validate::ensureNotEmpty($_POST, ["comment-id"]))
        {
            exit_json_error(_h("One or more fields are empty"));
        }

        try
        {
            Bug::deleteComment((int)$_POST["comment-id"]);
        }
        catch(BugException $e)
        {
            exit_json_error($e->getMessage());
        }

        exit_json_success(_h("Comment deleted"));
        break;

    default:
        exit_json_error(sprintf("action = %s is not recognized", h($_POST["action"])));
        break;
}
