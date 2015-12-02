<?php

namespace Icinga\Module\Director\Core;

use Icinga\Exception\IcingaException;
use Icinga\Module\Director\IcingaConfig\IcingaConfig;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Objects\DirectorDeploymentLog;

class CoreApi
{
    protected $client;

    protected $db;

    public function __construct(RestApiClient $client)
    {
        $this->client = $client;
    }

    // Todo: type
    public function setDb($db)
    {
        $this->db = $db;
        return $this;
    }

    public function getObjects($name, $pluraltype, $attrs = array())
    {
        $name = strtolower($name);
        $params = (object) array(
        );
        if (! empty($attrs)) {
            $params->attrs = $attrs;
        }

        return $this->client->get(
            'objects/' . urlencode(strtolower($pluraltype)),
            $params
        )->getResult('name');
    }

    public function getObject($name, $pluraltype, $attrs = array())
    {
        $params = (object) array(
        );
        if (! empty($attrs)) {
            $params->attrs = $attrs;
        }
        $url = 'objects/' . urlencode(strtolower($pluraltype)) . '/' . rawurlencode($name) . '?all_joins=1';
        return $this->client->get($url, $params)->getResult('name');
    }

    public function getConstants()
    {
        $constants = array();
        $command = 'var constants = [];
for (k => v in globals) {
   if (typeof(v) in [String, Number, Boolean]) {
      res = { name = k, value = v }
      constants.add({name = k, value = v})
   }
};
constants
';

        foreach ($this->client->post(
            'console/execute-script',
            array('command' => $command)
        )->getSingleResult() as $row) {
            $constants[$row->name] = $row->value;
        }

        return $constants;
    }

    public function getConstant($name)
    {
        $constants = $this->getConstants();
        if (array_key_exists($name, $constants)) {
            return $constants[$name];
        }

        return null;
    }

    public function getTypes()
    {
        return $this->client->get('types')->getResult('name');
    }

    public function getType($type)
    {
        $res = $this->client->get('types', array('name' => $type))->getResult('name');
        return $res[$type]; // TODO: error checking
    }

    public function getStatus()
    {
        return $this->client->get('status')->getResult('name');
    }

    public function listObjects($type, $pluralType)
    {
        // TODO: more abstraction needed
        // TODO: autofetch and cache pluraltypes
        $result = $this->client->get(
            'objects/' . $pluralType,
            array(
                'attrs' => array('__name')
            )
        )->getResult('name');

        return array_keys($result);
    }

    public function getModules()
    {
        return $this->client->get('config/packages')->getResult('name');
    }

    public function getActiveStageName()
    {
        return current($this->listModuleStages('director', true));
    }

    protected function getDirectorObjects($type, $plural, $map)
    {
        $attrs = array_merge(
            array_keys($map),
            array('package', 'templates', 'active')
        );

        $objects = array();
        $result  = $this->getObjects('zone', 'zones', $attrs);

        foreach ($result as $name => $row) {
            $attrs = $row->attrs;

            $properties = array(
                'object_name' => $name,
                'object_type' => 'object'
            );

            foreach ($map as $key => $prop) {
                if (property_exists($attrs, $key)) {
                    $properties[$prop] = $attrs->$key;
                }
            }

            $objects[$name] = IcingaObject::createByType($type, $properties, $this->db);
            if (property_exists($attrs, 'templates')
                && count($attrs->templates) > 1
                && $objects[$name]->supportsImports()
            ) {
                $imports = $attrs->templates;
                array_shift($imports);
                // TODO (prefetch?): $objects[$name]->imports = $imports;
            }
        }

        return $objects;
    }

    public function getZoneObjects()
    {
        return $this->getDirectorObjects('Zone', 'zones', array(
            'parent' => 'parent',
            'global' => 'is_global',
        ));
    }

    public function getCheckCommandObjects()
    {
        return $this->getObjects('CheckCommand', 'CheckCommands');
        return $this->getMyObjects('Zone', 'zones', array(
            'parent' => 'parent',
            'global' => 'is_global',
        ));
    }

    public function listModuleStages($name, $active = null)
    {
        $modules = $this->getModules();
        $found = array();

        if (array_key_exists($name, $modules)) {
            $module = $modules[$name];
            $current = $module->{'active-stage'};
            foreach ($module->stages as $stage) {
                if ($active === null) {
                    $found[] = $stage;
                } elseif ($active === true) {
                    if ($current === $stage) {
                        $found[] = $stage;
                    }
                } elseif ($active === false) {
                    if ($current !== $stage) {
                        $found[] = $stage;
                    }
                }
            }
        }

        return $found;
    }

    public function wipeInactiveStages()
    {
        $moduleName = 'director';
        foreach ($this->listModuleStages($moduleName, false) as $stage) {
            $this->client->delete('config/stages/' . $moduleName . '/' . $stage);
        }
    }

    public function listStageFiles($stage)
    {
        return array_keys(
            $this->client->get(
                'config/stages/director/' . $stage
            )->getResult('name', array('type' => 'file'))
        );
    }

    public function getStagedFile($stage, $file)
    {
        return $this->client->getRaw(
            'config/files/director/' . $stage . '/' . urlencode($file)
        );
    }

    public function hasModule($moduleName)
    {
        $modules = $this->getModules();
        return array_key_exists($moduleName, $modules);
    }

    public function createModule($moduleName)
    {
        return $this->client->post('config/packages/' . $moduleName)->succeeded();
    }

    public function deleteModule($moduleName)
    {
        return $this->client->delete('config/packages/' . $moduleName)->succeeded();
    }

    public function assertModuleExists($moduleName)
    {
        if (! $this->hasModule($moduleName)) {
            if (! $this->createModule($moduleName)) {
                throw new IcingaException(
                    'Failed to create the module "%s" through the REST API',
                    $moduleName
                );
            }
        }

        return $this;
    }

    public function deleteStage($moduleName, $stageName)
    {
        return $this->client->delete('config/stages', array(
            'module' => $moduleName,
            'stage'  => $stageName
        ))->succeeded();
    }

    public function dumpConfig(IcingaConfig $config, $db, $moduleName = 'director')
    {
        $start = microtime(true);
        $data = $config->getFileContents();
        $deployment = DirectorDeploymentLog::create(array(
            // 'config_id'     => $config->id,
            // 'peer_identity' => $endpoint->object_name,
            'peer_identity'   => $this->client->getPeerIdentity(),
            'start_time'      => date('Y-m-d H:i:s'),
            'config_checksum' => $config->getChecksum()
            // 'triggered_by'  => Util::getUsername(),
            /// 'username'  => Util::getUsername(),
            // 'module_name'   => $moduleName,
        ));

        $this->assertModuleExists($moduleName);

        $response = $this->client->post(
            'config/stages/' . $moduleName,
            array(
                'files' => $config->getFileContents()
            )
        );

        $duration = (int) ((microtime(true) - $start) * 1000);
        // $deployment->duration_ms = $duration;
        $deployment->duration_dump = $duration;

        if ($response->succeeded()) {
            if ($stage = $response->getResult('stage', array('package' => $moduleName))) { // Status?
                $deployment->stage_name = key($stage);
                $deployment->dump_succeeded = 'y';
            } else {
                $deployment->dump_succeeded = 'n';
            }
        } else {
            $deployment->dump_succeeded = 'n';
        }

        $deployment->store($db);
        return $deployment->dump_succeeded === 'y';
    }
}
