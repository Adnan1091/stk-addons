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

$bug_id = isset($_GET["bug_id"]) ? $_GET["bug_id"] : "";

if(empty($bug_id))
{
    echo "No bug id provided";
    exit;
}

if(!Bug::bugExists($bug_id))
{
    echo "Bug $bug_id does not exist";
    exit;
}

$tpl = new StkTemplate("bugs-view.tpl");
$bug = Bug::get($_GET["bug_id"]);
$tplData = array(
    "title" => $bug->getTitle(),
    "user"  => User::getFromID($bug->getUserId())->getUserName()
);

$tpl->assign("bug", $tplData);
echo $tpl;
