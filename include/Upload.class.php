<?php
/**
 * copyright 2012 Stephen Just <stephenjust@users.sf.net>
 *           2014 Daniel Butum <danibutum at gmail dot com>
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

/**
 * Class Upload
 */
class Upload
{
    /**
     * The uploaded file name
     * @var string
     */
    private $file_name;

    /**
     * The uploaded file type. eg: application/zip
     * @var string
     */
    private $file_type;

    /**
     * The uploaded file size
     * @var int
     */
    private $file_size;

    /**
     * The uploaded file temporary name path
     * @var string
     */
    private $file_tmp;

    /**
     * The upload file extension
     * @var string
     */
    private $file_ext;

    /**
     * The expected file type
     * @var int
     */
    private $expected_file_type;

    /**
     * @var string
     */
    private $destination;

    /**
     * The directory we create to extract the archive
     * @var string
     */
    private $temp_dir;

    /**
     * Hold the data from the zip archive. b3d files, license, texture etc
     * @var array
     */
    private $properties = [];

    /**
     * @var string
     */
    private $upload_type;

    /**
     * @var string
     */
    private $upload_name;

    /**
     * @var string
     */
    private $addon_name;

    /**
     * @var int
     */
    private $addon_id;

    /**
     * Hold the addon moderator message
     * @var string
     */
    private $moderator_message;

    /**
     * Hold the warning messages
     * @var array
     */
    private $warnings = [];

    /**
     * Hold the success messages
     * @var array
     */
    private $success = [];

    /**
     * Constructor
     *
     * @param array  $file_record
     * @param int    $expected_type see File::SOURCE, FILE::ADDON
     * @param string $moderator_message
     */
    public function __construct($file_record, $expected_type, $moderator_message)
    {
        $this->file_name = $file_record['name'];
        $this->file_type = $file_record['type'];
        $this->file_tmp = $file_record['tmp_name'];
        $this->file_size = $file_record['size'];
        $this->expected_file_type = $expected_type;
        $this->moderator_message = $moderator_message;

        // validate
        static::checkUploadError($file_record['error']);
        $this->file_ext = static::checkUploadExtension($this->file_name, $this->expected_file_type);

        $this->temp_dir = TMP_PATH . 'uploads' . DS . time() . '-' . $this->file_name . DS;

        // Clean up old temp files to make room for new upload
        File::deleteOldSubdirectories(TMP_PATH . 'uploads', Util::SECONDS_IN_A_HOUR);

        $this->doUpload();
    }

    /**
     * Deconstructor, delete the temp file
     */
    public function __destruct()
    {
        $this->removeTempFiles();
    }

    /**
     * Remove all the files from the temporary directory
     */
    public function removeTempFiles()
    {
        File::deleteDir($this->temp_dir);
    }

    /**
     * Get the success message
     * @return string
     */
    public function getSuccessMessage()
    {
        return implode("<br>", $this->success);
    }

    /**
     * Get the warning message
     * @return string
     */
    public function getWarningMessage()
    {
        return implode("<br>", $this->warnings);
    }

    /**
     * Perform the actual upload to our server
     *
     * @throws UploadException
     */
    private function doUpload()
    {
        if (!mkdir($this->temp_dir, 0755, true))
        {
            throw new UploadException('Failed to create temporary directory for upload: ' . h($this->temp_dir));
        }
        $file_archive = $this->temp_dir . $this->file_name;

        // Copy file to temp folder
        try
        {
            File::moveUploadFile($this->file_tmp, $file_archive);
        }
        catch(FileException $e)
        {
            throw new UploadException($e->getMessage());
        }

        // treat images separately
        if ($this->expected_file_type === File::IMAGE)
        {
            $this->doImageUpload();

            return null;
        }

        // load the data from the archive, and validate it
        try
        {
            File::extractArchive($file_archive, $this->temp_dir, $this->file_ext);
            File::flattenDirectory($this->temp_dir, $this->temp_dir);
            Upload::removeInvalidFiles();
            Upload::parseFiles();
        }
        catch(FileException $e)
        {
            if (file_exists($file_archive))
            {
                unlink($file_archive);
            }
            throw new UploadException("File Exception: " . $e);
        }
        catch(ParserException $e)
        {
            throw new UploadException("Parser Exception: " . $e->getMessage());
        }

        // empty xml files
        if (empty($this->properties['xml_attributes']))
        {
            throw new UploadException(_h("The archive does not contain any addon information"));
        }

        // --------------------------------------------------------------------
        // FIXME: This is only a temporary measure!
        // --------------------------------------------------------------------
        if ($this->properties['xml_attributes']['version'] > 5 && $this->upload_type === Addon::TRACK)
        {
            throw new UploadException('You uploaded a track with version ' . $this->properties['xml_attributes']['version']
                . ' of the track format.<br />'
                . 'This new format is not yet supported by stkaddons. The stkaddons developer is working on distributing '
                . 'add-ons in a sort of "main package/dependency" manner to save internet bandwidth for users by '
                . 'sharing resources. The developer is using the format change to ensure STK 0.7.x can still access '
                . 'their own addons without disruption.<br /> Thank you for your patience. The developer hopes to '
                . 'have this finished before any "beta" versions of STK 0.8 are released.');
        }

        // Make sure the parser found a license file, and load it into the xml atributes
        if (empty($this->properties['license_file']))
        {
            throw new UploadException(_h(
                    'A valid License.txt file was not found. Please add a License.txt file to your archive and re-submit it.'
                )
            );
        }
        $this->properties['xml_attributes']['license'] = h(file_get_contents($this->properties['license_file'], false));

        // Get addon id from page request if possible
        $addon_id = null;
        if (isset($_GET['name']))
        {
            $addon_id = Addon::cleanId($_GET['name']);
            if (!Addon::exists($addon_id))
            {
                $addon_id = null;
            }
            elseif ($this->expected_file_type !== File::SOURCE)
            {
                $addon = Addon::get($addon_id);
                $revisions = $addon->getAllRevisions();
                end($revisions);
                $this->properties['addon_revision'] = key($revisions) + 1;
                unset($addon);
            }
        }

        // For source packages
        if ($this->expected_file_type === File::SOURCE)
        {
            if (!$addon_id)
            {
                throw new UploadException('No add-on id was provided with your source archive.');
            }
            if (!Addon::exists($addon_id))
            {
                throw new UploadException('The add-on you want to add a source file to does not exist');
            }
            if (!$this->upload_type)
            {
                $this->upload_type = $_GET['type'];
            }

            $filetype = 'source';
        }
        else // For add-on files
        {

            if ($this->upload_type === null)
            {
                throw new UploadException('No add-on information file was found.');
            }

            // Get addon id from XML if we still don't have it
            if (!preg_match('/^[a-z0-9\-]+_?[0-9]*$/i', $addon_id) || $addon_id === null)
            {
                $addon_id = Addon::generateId($this->upload_type, $this->properties['xml_attributes']['name']);
                $this->properties['addon_revision'] = 1;
            }

            static::editInfoFile();

            // Get image file
            if ($this->upload_type === Addon::KART)
            {
                $image_file = $this->properties['xml_attributes']['icon-file'];
            }
            else
            {
                $image_file = $this->properties['xml_attributes']['screenshot'];
            }
            $image_file = $this->temp_dir . $image_file;

            if (!file_exists($image_file))
            {
                if (DEBUG_MODE)
                {
                    var_dump($image_file);
                }
                throw new UploadException(_h("A screenshot/icon file does not exist in the archive(file name is case sensitive)"));
            }

            // Get image file extension
            preg_match('/\.([a-z]+)$/i', $image_file, $image_ext);
            if (count($image_ext) !== 2)
            {
                throw new UploadException(sprintf("The image = '%s', does not have a file extension", h($image_file)));
            }
            $image_ext = $image_ext[1];

            // Save file to local filesystem
            $file_id = static::generateUniqueFileName(UP_PATH . 'images' . DS, $image_ext);
            $image_path = 'images' . DS . $file_id . '.' . $image_ext;
            $this->properties['image_path'] = UP_PATH . $image_path;
            copy($image_file, $this->properties['image_path']);

            // Record image file in database
            try
            {
                DBConnection::get()->query(
                    "CALL `" . DB_PREFIX . "create_file_record`
                    (:addon_id, :upload_type, 'image', :file, @result_id)",
                    DBConnection::NOTHING,
                    [
                        ":addon_id"    => $addon_id,
                        ":upload_type" => $this->upload_type,
                        ":file"        => $image_path
                    ]
                );
            }
            catch(DBException $e)
            {
                $this->warnings[] = _h('Failed to associate image file with addon.');
                unlink($this->properties['image_path']);
                $image_file = null;
            }

            try
            {
                $id = DBConnection::get()->query(
                    'SELECT @result_id',
                    DBConnection::FETCH_FIRST
                );

                $image_file = $id["@result_id"];
            }
            catch(DBException $e)
            {
                $image_file = null;
                trigger_error("Could not select the return from the procedure", E_ERROR);
            }

            $this->properties['image_file'] = $image_file;

            try
            {
                if (isset($this->properties['quad_file']))
                {
                    File::newImageFromQuads($this->properties['quad_file'], $addon_id, $this->upload_type);
                }
            }
            catch(FileException $e)
            {
                throw new UploadException($e->getMessage());
            }

            $filetype = 'addon';
        }
        $this->addon_id = $addon_id;

        // Validate addon type field
        if (!Addon::isAllowedType($this->upload_type))
        {
            throw new UploadException(_h('Invalid add-on type.'));
        }

        // Pack zip file
        $this->destination = UP_PATH;
        $this->generateFilename('zip');

        try
        {
            File::compress($this->temp_dir, $this->upload_name);
        }
        catch(FileException $e)
        {
            throw new UploadException(_h('Failed to re-pack archive file. Reason: ' . $e->getMessage()));

        }

        // Record addon's file in database
        try
        {
            DBConnection::get()->query(
                "CALL `" . DB_PREFIX . "create_file_record`
                (:addon_id, :upload_type, :file_type, :file, @result_id)",
                DBConnection::NOTHING,
                [
                    ":addon_id"    => $addon_id,
                    ":upload_type" => $this->upload_type,
                    ":file_type"   => $filetype,
                    ":file"        => basename($this->upload_name)
                ]
            );
        }
        catch(DBException $e)
        {
            unlink($this->upload_name);

            if ($this->expected_file_type !== File::SOURCE)
            {
                $this->properties['xml_attributes']['fileid'] = 0;
            }

            throw new UploadException(_h('Failed to associate archive file with addon.'));
        }

        try
        {
            $id = DBConnection::get()->query(
                'SELECT @result_id',
                DBConnection::FETCH_FIRST
            );
            $this->properties['xml_attributes']['fileid'] = $id["@result_id"];
        }
        catch(DBException $e)
        {
            $this->properties['xml_attributes']['fileid'] = 0;

            trigger_error("Could not select the return from the procedure", E_ERROR);
        }


        if ($this->expected_file_type === File::SOURCE)
        {
            $this->success[] = _h('Successfully uploaded source archive.');
            $this->success[] = File::link('addons.php?type=' . $this->upload_type . '&amp;name=' . $this->addon_id, _h('Continue.'));

            return null;
        }

        // Set first revision to be "latest"
        if ($this->properties['addon_revision'] == 1)
        {
            $this->properties['status'] += F_LATEST;
        }

        $this->properties['xml_attributes']['status'] = $this->properties['status'];
        $this->properties['xml_attributes']['image'] = $this->properties['image_file'];
        $this->properties['xml_attributes']['missing_textures'] = $this->properties['missing_textures'];

        // add addon to database
        try
        {
            if (Addon::exists($this->addon_id)) // new revision
            {
                $addon = Addon::get($this->addon_id);

                // Check if we are the original uploader, or a moderator
                if (User::getLoggedId() != $addon->getUploaderId() && !User::hasPermission(AccessControl::PERM_EDIT_ADDONS))
                {
                    throw new UploadException(_h('You do not have the necessary permissions to perform this action.'));
                }
                $addon->createRevision($this->properties['xml_attributes'], $file_id, $this->moderator_message);
            }
            else // new addon
            {
                // Check if we were trying to add a new revision
                if ($this->properties['addon_revision'] != 1)
                {
                    throw new UploadException(_h('You are trying to add a new revision of an add-on that does not exist.'));
                }

                Addon::create($this->upload_type, $this->properties['xml_attributes'], $file_id, $this->moderator_message);
            }
        }
        catch(AddonException $e)
        {
            throw new UploadException($e->getMessage());
        }

        $this->success[] =
            _h('Your add-on was uploaded successfully. It will be reviewed by our moderators before becoming publicly available.');
        $this->success[] = '<a href="upload.php?type=' . $this->upload_type . '&amp;name=' . $this->addon_id . '&amp;action=file">' .
            _h('Click here to upload the sources to your add-on now.')
            . '</a>';
        $this->success[] = _h(
            '(Uploading the sources to your add-on enables others to improve your work and also ensure your add-on will not be lost in the future if new SuperTuxKart versions are not compatible with the current format.)'
        );
        $this->success[] = '<a href="' . File::rewrite('addons.php?type=' . $this->upload_type . '&amp;name=' . $this->addon_id) . '">'
            . _h('Click here to view your add-on.') . '</a>';

        return null;
    }

    /**
     * Perform the upload of an image to the server
     *
     * @throws UploadException
     */
    private function doImageUpload()
    {
        $this->destination = UP_PATH . 'images' . DS;
        $this->generateFilename();
        $addon_id = Addon::cleanId($_GET['name']);
        $addon_type = $_GET['type'];

        rename($this->temp_dir . $this->file_name, $this->upload_name);

        try
        {
            File::newImage(null, $this->upload_name, $addon_id, $addon_type);
        }
        catch(FileException $e)
        {
            throw new UploadException($e->getMessage());
        }

        $this->success[] = _h('Successfully uploaded image.');
        $this->success[] = '<a href="addons.php?type=' . $_GET['type'] . '&amp;name=' . $_GET['name'] . '">' . _h('Continue.') . '</a>';
    }

    /**
     * Remove all the files that are considered invalid
     */
    private function removeInvalidFiles()
    {
        // Check for invalid files
        $invalid_files = File::removeInvalidFiles($this->temp_dir, $this->expected_file_type === File::SOURCE);

        if ($invalid_files)
        {
            $this->warnings[] = _h('Some invalid files were found in the uploaded add-on. These files have been removed from the archive:')
                . ' ' . h(implode(', ', $invalid_files));
        }
    }

    /**
     * Generate a random file name for our upload_name attribute
     *
     * @param string $file_ext optional param
     *
     * @throws UploadException if the destination is not set
     */
    private function generateFilename($file_ext = null)
    {
        if (!$file_ext)
        {
            $file_ext = $this->file_ext;
        }
        if (!$this->destination)
        {
            throw new UploadException(_h('A destination has not been set yet'));
        }

        $file_id = static::generateUniqueFileName($this->destination, $file_ext);
        $this->upload_name = $this->destination . $file_id . "." . $file_ext;
    }

    /**
     * Parse the b3d files
     */
    private function parseFiles()
    {
        $files = scandir($this->temp_dir);

        // Initialize counters
        $b3d_textures = [];

        // Loop through all files
        foreach ($files as $file)
        {
            if ($file === '.' || $file === '..')
            {
                continue;
            }

            // Parse any B3D models
            if (preg_match('/\.b3d$/i', $file))
            {
                $b3d_parse = new B3DParser();
                $b3d_parse->loadFile($this->temp_dir . $file);
                $b3d_textures = array_merge($b3d_parse->listTextures(), $b3d_textures);
            }

            // Parse any XML files
            if (preg_match('/\.xml/i', $file))
            {
                $xml_parse = new AddonXMLParser();
                $xml_parse->loadFile($this->temp_dir . $file);
                $xml_type = $xml_parse->getType();

                if ($xml_type === 'TRACK' || $xml_type === 'KART')
                {
                    $this->properties['xml_attributes'] = $xml_parse->addonFileAttributes();

                    if ($xml_type === 'TRACK')
                    {
                        if ($file !== 'track.xml')
                        {
                            continue;
                        }

                        if ($this->properties['xml_attributes']['arena'] === 'Y')
                        {
                            $this->upload_type = Addon::ARENA;
                        }
                        else
                        {
                            $this->upload_type = Addon::TRACK;
                        }
                    }
                    else
                    {
                        if ($file !== 'kart.xml')
                        {
                            continue;
                        }

                        $this->upload_type = Addon::KART;
                    }

                    $this->properties['addon_file'] = $this->temp_dir . $file;
                    $this->addon_name = $this->properties['xml_attributes']['name'];
                }

                if ($xml_type === 'QUADS')
                {
                    $this->properties['quad_file'] = $this->temp_dir . $file;
                }
            }

            // Mark an existing license file
            if (preg_match('/^license\.txt$/i', $file))
            {
                $this->properties['license_file'] = $this->temp_dir . $file;
            }
        }

        // Initialize the status flag
        $this->properties['status'] = 0;

        // Check to make sure all image dimensions are powers of 2
        if (!File::imageCheck($this->temp_dir))
        {
            $this->warnings[] = _h('Some images in this add-on do not have dimensions that are a power of two.') . ' ' .
                _h('This may cause display errors on some video cards.');
            $this->properties['status'] += F_TEX_NOT_POWER_OF_2;
        }

        // List missing textures
        $this->properties['b3d_textures'] = $b3d_textures;
        $missing_textures = [];
        foreach ($this->properties['b3d_textures'] as $tex)
        {
            if (!file_exists($this->temp_dir . $tex))
            {
                $missing_textures[] = $tex;
            }
        }

        // Remove duplicate values
        $this->properties['missing_textures'] = array_unique($missing_textures, SORT_STRING);
    }

    /**
     * Rewrite the addon file with the revision attribute
     */
    private function editInfoFile()
    {
        $xml_parse = new AddonXMLParser();
        $xml_parse->loadFile($this->properties['addon_file'], true);
        $xml_parse->setAttribute('groups', 'Add-Ons');
        $xml_parse->setAttribute('revision', $this->properties['addon_revision']);
        $xml_parse->writeAttributes();
    }

    /**
     * Generate a unique file name tha does not exist
     *
     * @param string $directory the directory where the file resides
     * @param string $extension the extension of the file
     *
     * @return string the filename
     */
    private static function generateUniqueFileName($directory, $extension)
    {
        $filename = uniqid(mt_rand());
        while (file_exists($directory . $filename . '.' . $extension))
        {
            $filename = uniqid(mt_rand());
        }

        return $filename;
    }

    /**
     * Read the error code passed and throw an appropriate exception
     *
     * @param int $error_code
     *
     * @throws UploadException
     */
    private static function checkUploadError($error_code)
    {
        switch ($error_code)
        {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
                throw new UploadException(_h('Uploaded file is too large.'));
            case UPLOAD_ERR_FORM_SIZE:
                throw new UploadException(_h('Uploaded file is too large.'));
            case UPLOAD_ERR_PARTIAL:
                throw new UploadException(_h('Uploaded file is incomplete.'));
            case UPLOAD_ERR_NO_FILE:
                throw new UploadException(_h('No file was uploaded.'));
            case UPLOAD_ERR_NO_TMP_DIR:
                throw new UploadException(_h('There is no TEMP directory to store the uploaded file in.'));
            case UPLOAD_ERR_CANT_WRITE:
                throw new UploadException(_h('Unable to write uploaded file to disk.'));
            case UPLOAD_ERR_EXTENSION:
                throw new UploadException(_h('Upload file stopped by extension'));
            default:
                throw new UploadException(_h('Unknown file upload error.') . $error_code);
        }
    }

    /**
     * Check the filename for an uploaded file to make sure the extension is one that can be handled
     *
     * @param string $filename
     * @param int    $type
     *
     * @throws UploadException
     * @return string
     */
    public static function checkUploadExtension($filename, $type = -1)
    {
        // Check file-extension for uploaded file
        if ($type === File::IMAGE)
        {
            if (!preg_match('/\.(png|jpg|jpeg)$/i', $filename, $file_ext))
            {
                throw new UploadException(_h('Uploaded image files must be either PNG or JPEG files.'));
            }
        }
        else
        {
            // File extension must be .zip, .tgz, .tar, .tar.gz, tar.bz2, .tbz
            if (!preg_match('/\.(zip|t[bg]z|tar|tar\.gz|tar\.bz2)$/i', $filename, $file_ext))
            {
                throw new UploadException(_h('The file you uploaded was not the correct type.'));
            }
        }

        return $file_ext[1];
    }
}
