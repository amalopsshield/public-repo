<?php

namespace Panel\Jobs;
use Panel\Core\Git as GitCore;
use Helpers\Job;
use Helpers\Git as gitHelper;
use App\DirectSession;
use Panel\Model\SessionModel;

class Git
{

    public static function clone($data)
    {
        $data = (array) $data;
        $job = new Job(JOBID, 'git-clone');

        $user = posix_getpwnam($data['website']->username);
        if (!$user || empty($user['dir'])) {
            $job->error('error', ['error' => 'Invalid system user']);
            return false;
        }

        $home = rtrim($user['dir'], '/');

        $destination = trim($data['destination'], '/');

        if (empty($destination) || str_contains($destination, '..')) {
            $job->error('error', ['error' => 'invalid-destination-path']);
            return false;
        }
        $repo_path = rtrim($home . '/' . $destination, '/');

        if (!is_dir($repo_path)) {
            mkdir($repo_path, 0755, true);
            chown($repo_path, $data['website']->username);
        }

        $job->update('started');

        $helper = new gitHelper($repo_path);

        $result = $helper->clone(
            $job,
            $repo_path,
            $data['clone_url'],
            $data['branch'],
            $data['auth_type'] === 'ssh',
            $data['ssh_key_path'] ?? NULL
        );

        // get the source type github, gitlab, bitbucket, other
        $source = $helper->getSourceType($data['clone_url']);

        $git_dir = "/opt/cpguard/app/data/users/" . $data['website']->username . "/applications";

        if (!is_dir($git_dir)) {
            mkdir($git_dir, 0755, true);
            chown($git_dir, $data['website']->username);
        }

        $data_file = [];
        if (file_exists($git_dir . '/git.json')) {
            $data_file = json_decode(file_get_contents($git_dir . '/git.json'), true);
        }

        if (!empty($result['output']) && strpos($result['output'], 'already exists and is not an empty directory') !== false) {
            $job->error('error', $result);
            return false;
        }

        $data_file[] = [
            'app_id' => "app_" . uniqid(),
            'repository_name' => $data['repository_name'],
            'domain' => $data['website']->domain,
            'website_id' => $data['website']->id,
            'repo_path' => $repo_path,
            'source' => $source,
            'clone_url' => $data['clone_url'],
            'branch' => $data['branch'],
            'ssh_key_path' => $data['ssh_key_path'] ?? NULL,
            'status' => $result === true,
            'errors' => $result['output'] ?? NULL,
            'created_at' => date('Y-m-d H:i:s')
        ];

        exec("chown -R " . escapeshellarg($data['website']->username) . ":" . escapeshellarg($data['website']->username) . " " . escapeshellarg($repo_path));

        file_put_contents($git_dir . '/git.json', json_encode($data_file, JSON_PRETTY_PRINT));
        chown($git_dir . '/git.json', $data['website']->username);

        if ($result !== true) {
            dlog("Error cloning repository: " . json_encode($result));
            $job->error('error', $result);
            return false;
        }

        $job->update('completed');
        return true;
    }

    public function list($data)
    {
        $data = (array) $data;

        $this->cleanup($data);

        $git_dir = "/opt/cpguard/app/data/users/" . $data['username'] . "/applications";
        $data_file = [];
        if (file_exists($git_dir . '/git.json')) {
            $data_file = json_decode(file_get_contents($git_dir . '/git.json'), true);
        }

        return $data_file;
    }

    public function details($data)
    {
        $data = (array) $data;

        $this->cleanup($data);

        if (empty($data['id']) || empty($data['username'])) {
            return [];
        }

        $gitDir = "/opt/cpguard/app/data/users/{$data['username']}/applications";
        $gitJson = $gitDir . '/git.json';

        if (!is_file($gitJson)) {
            return [];
        }

        $dataFile = json_decode(file_get_contents($gitJson), true) ?: [];

        $repo = null;

        foreach ($dataFile as $entry) {
            if (($entry['app_id'] ?? null) === $data['id']) {
                $repo = $entry;
                break;
            }
        }

        if ($repo === null || empty($repo['repo_path'])) {
            return [];
        }
        $repo['repository_name'] = $repo['repository_name'] ?? basename($repo['repo_path']);
        $repo_path = rtrim($repo['repo_path'], '/');

        if (!is_dir($repo_path)) {
            return [];
        }

        $gitHelper = new gitHelper($repo_path);
        $output = $gitHelper->getGitRepos($repo['branch']);

        if (is_array($output) && !empty($output)) {
            $deploy_action =
                !empty($output['deploy_script'])
                || (
                    ($repo['deployment']['files_copy_switch'] ?? false)
                    && !empty($repo['deployment']['copy_files']['from'])
                    && !empty($repo['deployment']['copy_files']['to'])
                );
            $output['id'] = $repo['app_id'];
            $output['branch'] = $repo['branch'];
            $output['repository_name'] = $repo['repository_name'];
            $output['deployed_head'] = $repo['deployed_head'] ?? NULL;
            $output['last_deployed_at'] = $repo['last_deployed_at'] ?? NULL;
            $output['deploy_status'] = $repo['deploy_status'] ?? NULL;
            $output['source'] = $repo['source'] ?? 'other';
            $output['deploy'] = $deploy_action;
        }

        return $output ?? [];
    }

    public function cleanup($data)
    {
        $data = (array) $data;

        $git_dir = "/opt/cpguard/app/data/users/" . $data['username'] . "/applications";
        $data_file = [];
        if (file_exists($git_dir . '/git.json')) {
            $data_file = json_decode(file_get_contents($git_dir . '/git.json'), true);
        }

        $updated = false;
        foreach ($data_file as $key => $repo) {
            $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
            if (empty($repo_path) || !is_dir($repo_path) || !is_dir($repo_path . '/.git')) {
                unset($data_file[$key]);
                $updated = true;
            }
        }

        if ($updated) {
            file_put_contents($git_dir . '/git.json', json_encode(array_values($data_file), JSON_PRETTY_PRINT));
            chown($git_dir . '/git.json', $data['username']);
        }
    }

    public function commitDetails($data)
    {
        $repo = $this->getJsonInfo($data->id, username: $data->username);
        $branch = $repo['branch'] ?? 'main';
        if (empty($repo)) {
            return 'repository-not-found';
        }

        $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            return 'repository-not-found';
        }

        $gitHelper = new gitHelper($repo_path);

        return $gitHelper->commitDetail($data->hash, $branch);
    }

    public function getConfig($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }

        $settings = [
            'clone_url' => $repo['clone_url'] ?? '',
            'branch' => $repo['branch'] ?? 'main',
            'ssh_key' => $repo['ssh_key'] ?? ''
        ];

        $user_ssh_keys = queue()->wait("Ssh::list", ['username' => $data->username]);
        return ['settings' => $settings, 'ssh_keys' => $user_ssh_keys];
    }

    function setConfig($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }

        $git_dir = "/opt/cpguard/app/data/users/" . $data->username . "/applications";
        $git_json = $git_dir . '/git.json';

        $entries = [];
        if (is_file($git_json)) {
            $entries = json_decode(file_get_contents($git_json), true) ?: [];
        }

        $ssh_key_path = null;
        if (!empty($data->config->ssh_key)) {
            $ssh_key_path = "/home/" . $data->username . "/.ssh/" . $data->config->ssh_key;
        }

        $found = false;

        foreach ($entries as &$entry) {
            if ($entry['app_id'] === $data->id) {
                $entry['clone_url'] = $data->config->clone_url;
                $entry['branch'] = $data->config->branch;
                $entry['ssh_key'] = $data->config->ssh_key ?? '';
                $entry['ssh_key_path'] = $ssh_key_path;
                $found = true;
                break;
            }
        }
        unset($entry);

        if (!$found) {
            $entries[] = [
                'app_id' => $data->id,
                'clone_url' => $data->config->clone_url,
                'branch' => $data->config->branch,
                'ssh_key' => $data->config->ssh_key,
                'ssh_key_path' => $ssh_key_path,
            ];
        }

        file_put_contents(
            $git_json,
            json_encode($entries, JSON_PRETTY_PRINT),
            LOCK_EX
        );

        @chown($git_json, $data->username);
        @chgrp($git_json, $data->username);
        chmod($git_json, 0644);

        return true;
    }

    public function getDeployConfig($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }

        if (empty($repo['deployment'])) {
            $settings = [
                'webhook_switch' => false,
                'session_url' => "",
                'application_type' => 'generic',
                // 'deployment_switch' => false,
                'deployment_script' => '',
                'files_copy_switch' => false,
                'copy_files' => [],
            ];
        } else {
            $deployment = $repo['deployment'];
            if (file_exists(rtrim($repo['repo_path'], '/') . '/.git/cpguard.deploy')) {
                $deploy_file = file_get_contents(rtrim($repo['repo_path'], '/') . '/.git/cpguard.deploy');
            }
            $ds = new DirectSession();
            $settings = [
                'webhook_switch' => $deployment['webhook_switch'] ?? false,
                'webhook_url' => !$deployment['webhook_switch'] || empty($deployment['session']) ? "" : $ds->getUrl(['method' => 'deploy', 'id' => $deployment['session']['id']]),
                'application_type' => $deployment['application_type'] ?? 'generic',
                // 'deployment_switch' => $deployment['deployment_switch'] ?? '',
                'deployment_script' => $deployment['deployment_script'] ?? ($deploy_file ?? ''),
                'files_copy_switch' => $deployment['files_copy_switch'] ?? false,
                'copy_files' => $deployment['copy_files'] ?? [],
            ];
        }
        $settings['id'] = $data->id;
        $settings['status'] = true;

        return $settings;
    }

    public function setDeployConfig($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }

        $repo_path = rtrim($repo['repo_path'], '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            return 'repository-not-found';
        }

        $deploy_script_path = rtrim($repo_path, '/') . '/.git/cpguard.deploy';
        $deploy_script_content = $data->deploy_config->deployment_script ?? '';

        file_put_contents($deploy_script_path, $deploy_script_content, LOCK_EX);
        @chown($deploy_script_path, $data->username);
        @chgrp($deploy_script_path, $data->username);
        chmod($deploy_script_path, 0755);

        $git_dir = "/opt/cpguard/app/data/users/" . $data->username . "/applications";
        $git_json = $git_dir . '/git.json';

        $entries = [];
        if (is_file($git_json)) {
            $entries = json_decode(file_get_contents($git_json), true);
        }

        $ds = new DirectSession();
        foreach ($entries as &$entry) {
            if ($entry['app_id'] === $data->id) {

                $webhook = (bool) $data->deploy_config->webhook_switch;
                $prev_webhook = (bool) ($entry['deployment']['webhook_switch'] ?? false);
                $session = $entry['deployment']['session'] ?? null;

                /* -----------------------------------------
                 | Handle webhook toggle
                 |------------------------------------------*/
                if ($webhook !== $prev_webhook) {
                    if ($webhook) {
                        if (!empty($session)) {
                            $existing = $ds->getBySessionId($session['id']);
                            if (empty($existing)) {
                                $session = $ds->create('GitHandler::deploy', [
                                    'app_id' => $data->id,
                                    'username' => $data->username
                                ], 0);
                            } elseif ($existing['status'] !== 1) {
                                $ds->update($existing['id'], ['status' => 1]);
                                $session = $existing;
                            }
                        } else {
                            $session = $ds->create('GitHandler::deploy', [
                                'app_id' => $data->id,
                                'username' => $data->username
                            ], 0);
                        }

                    } else {
                        // Webhook turned OFF
                        if (!empty($session)) {
                            $ds->update($session['id'], ['status' => 0]);
                        }
                        $session = null;
                    }

                } elseif ($webhook && empty($session)) {
                    // Webhook already ON but session missing
                    $session = $ds->create('GitHandler::deploy', [
                        'app_id' => $data->id,
                        'username' => $data->username
                    ], 0);
                }

                /* -----------------------------------------
                 | Final deployment payload
                 |------------------------------------------*/
                $entry['deployment'] = [
                    'webhook_switch' => $webhook,
                    'webhook_url' => (!$webhook || empty($session)) ? '' : $ds->getUrl($session),
                    'session' => $session,
                    'deployment_script' => $deploy_script_content,
                    'application_type' => $data->deploy_config->application_type,
                    'files_copy_switch' => (bool) ($data->deploy_config->files_copy_switch ?? false),
                    'copy_files' => $data->deploy_config->copy_files,
                ];
                break;
            }
        }

        file_put_contents(
            $git_json,
            json_encode($entries, JSON_PRETTY_PRINT),
            LOCK_EX
        );

        @chown($git_json, $data->username);
        return $this->getDeployConfig($data);
    }

    public function getEnvironment($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }
        return [
            'id' => $data->id,
            'environment' => $repo['environment'] ?? [],
            'environment_default_path' => $repo['repo_path'] . '/.env'
        ];

    }

    public function setEnvironment($data)
    {
        $baseDir = "/opt/cpguard/app/data/users/{$data->username}/applications";
        $gitJson = $baseDir . '/git.json';

        if (!is_file($gitJson)) {
            return 'repository-not-found';
        }

        $entries = json_decode(file_get_contents($gitJson), true) ?? [];

        $index = array_search($data->id, array_column($entries, 'app_id'), true);
        if ($index === false) {
            return 'repository-not-found';
        }

        $entry = $entries[$index];

        $env = $entry['environment'] ?? [
            'environment_path' => '',
            'type' => 'env',
            'values' => [],
        ];

        /* ---------- Normalize request ---------- */
        $req = [];
        if (isset($data->environment)) {
            $req = is_array($data->environment)
                ? $data->environment
                : (array) $data->environment;

            if (isset($req['values'])) {
                $req['values'] = is_array($req['values'])
                    ? $req['values']
                    : (array) $req['values'];
            }
        }

        $changed = false;

        /* ---------- Environment path ---------- */
        if (array_key_exists('environment_path', $req)) {

            if ($req['environment_path'] === '') {
                // Remove file if exists
                if (!empty($env['environment_path']) && is_file($env['environment_path'])) {
                    @unlink($env['environment_path']);
                }

                $env['environment_path'] = $entry['repo_path'] . '/.env';
                $env['values'] = [];
                $changed = true;

            } else {
                $real = $req['environment_path'];

                if (!str_starts_with($real, "/home/{$data->username}/")) {
                    return 'invalid-environment-path';
                }

                if ($real !== $env['environment_path']) {
                    $env['environment_path'] = $real;
                    $changed = true;
                }
            }
        }

        if (array_key_exists('values', $req)) {
            $env['values'] = [];

            foreach ($req['values'] as $k => $v) {
                $env['values'][(string) $k] = (string) $v;
            }

            $changed = true;
        }

        if (isset($req['type'])) {
            $env['type'] = in_array($req['type'], ['env', 'json'], true)
                ? $req['type']
                : 'env';

            $changed = true;
        }

        if (!empty($env['environment_path']) && $changed) {

            $dir = dirname($env['environment_path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (empty($env['values'])) {
                file_put_contents($env['environment_path'], '', LOCK_EX);
            } else {

                if ($env['type'] === 'json') {
                    $content = json_encode($env['values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } else {
                    $lines = [];
                    foreach ($env['values'] as $k => $v) {
                        $lines[] = sprintf('%s="%s"', $k, addslashes($v));
                    }
                    $content = implode("\n", $lines);
                }

                $tmp = $env['environment_path'] . '.tmp';
                file_put_contents($tmp, $content, LOCK_EX);
                rename($tmp, $env['environment_path']);
            }

            @chown($env['environment_path'], $data->username);
            @chgrp($env['environment_path'], $data->username);
            chmod($env['environment_path'], 0644);
        }

        $entry['environment'] = $env;
        $entries[$index] = $entry;

        file_put_contents($gitJson, json_encode($entries, JSON_PRETTY_PRINT), LOCK_EX);
        @chown($gitJson, $data->username);

        return true;
    }

    public function pull($data)
    {
        $job = new Job(JOBID, 'git-pull');
        $job->update('pull-started', ['id' => $data->id]);
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            $job->error('error', ['error' => 'repository-not-found', 'id' => $data->id]);
            return 'repository-not-found';
        }
        $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            $job->error('error', ['error' => 'repository-not-found', 'id' => $data->id]);
            return 'repository-not-found';
        }

        $gitHelper = new gitHelper($repo_path);

        $pull = $gitHelper->pull($repo['branch'] ?? 'main');

        $job->update('pull-completed', ['pull' => $pull, 'id' => $data->id]);

        if ($data->deploy && $pull['status'] === true) {
            $job->update('deploy-started', ['id' => $data->id]);
            $this->deploy($data, $job);
        }

        $job->close();
        return;
    }

    public function push($data)
    {
        $data = (array) $data;
        $repo_path = rtrim($data['repo_path'], '/');

        $gitHelper = new gitHelper($repo_path);

        return $gitHelper->push($data);
    }

    public function rollback($data)
    {
        $repo = self::getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }
        $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            return 'repository-not-found';
        }

        $gitHelper = new gitHelper($repo_path);
        return $gitHelper->rollback($data);
    }

    public function hardReset($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }
        $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            return 'repository-not-found';
        }

        $gitHelper = new gitHelper($repo_path);
        return $gitHelper->hardReset($data);
    }

    public function history($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }

        $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            return 'repository-not-found';
        }

        $gitHelper = new gitHelper($repo_path);

        return $gitHelper->history($data);
    }

    public function deploy($data, $pull = NULL)
    {
        dlog($pull);
        if ($pull === NULL) {
            $job = new Job(JOBID, 'git-deploy');
            $job->update('deploy-started', ['id' => $data->id]);
        } else {
            $job = $pull;
        }

        $git_dir = "/opt/cpguard/app/data/users/" . $data->username . "/applications";
        $git_json = $git_dir . '/git.json';
        $entries = [];
        if (is_file($git_json)) {
            $entries = json_decode(file_get_contents($git_json), true);
        }
        foreach ($entries as &$entry) {
            if ($entry['app_id'] === $data->id) {
                $entry['deploy_status'] = ['status' => true, 'error' => "", 'message' => 'Success'];
                break;
            }
        }

        file_put_contents($git_json, json_encode($entries, JSON_PRETTY_PRINT), LOCK_EX);
        @chown($git_json, $data->username);

        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            $job->error('error', ['error' => 'repository-not-found', 'id' => $data->id]);
            return false;
        }
        $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            $job->error('error', ['error' => 'repository-not-found', 'id' => $data->id]);
            return false;
        }

        $gitHelper = new gitHelper($repo_path);
        $script_result = $gitHelper->deploy(null, $job);

        $head = $gitHelper->getCurrentCommitHash();
        $script_status = $script_result['status'] ?? false;
        if ($script_result['message'] === 'no-deployment-script') {
            $script_status = NULL;
        }

        $copy_files = $repo['deployment']['copy_files'] ?? [];
        if (($repo['deployment']['files_copy_switch'] ?? false) && !empty($copy_files['from']) && !empty($copy_files['to'])) {

            $gitHelper = new gitHelper($repo_path);
            $copy = $gitHelper->copyFiles($copy_files, $data->username);
            $copy_status = $copy['status'] ?? false;
        } else {
            $copy_status = NULL;
        }

        $result = NULL;
        if ($head !== false) {
            if ($script_status === true && ($copy_status === true || $copy_status === NULL)) {
                $result = ['status' => true, 'error' => "", 'message' => 'Success'];
            } elseif ($script_status === NULL && $copy_status === NULL) {
                $result = ['status' => NULL, 'error' => "", 'message' => 'not-configured'];
            } else {
                $result = ['status' => false];
                if ($script_status === false) {
                    $result['error'] = $script_result['error'] ?? '';
                    $result['message'] = $script_result['message'] ?? '';
                } elseif ($copy_status === false) {
                    $result['error'] = $copy['error'] ?? '';
                    $result['message'] = $copy['message'] ?? '';
                }
            }

            foreach ($entries as &$entry) {
                if ($entry['app_id'] === $data->id) {
                    $entry['deployed_head'] = $head;
                    $entry['last_deployed_at'] = date('Y-m-d H:i:s');
                    $entry['deploy_status'] = $result;
                    break;
                }
            }
            file_put_contents($git_json, json_encode($entries, JSON_PRETTY_PRINT), LOCK_EX);
            @chown($git_json, $data->username);
        }

        if ($result['status'] === false) {
            $job->error('error', array_merge($result, ['id' => $data->id]));
            return false;
        }

        $job->update('deploy-completed', ['deploy' => $script_result, 'copy' => $copy ?? '', 'id' => $data->id]);
        if ($pull === NULL) {
            $job->close();
        }
        return ['deploy' => $script_result, 'copy' => $copy ?? ''];
    }

    public function delete($data)
    {
        $data = (object) $data;

        $repo = $this->getJsonInfo($data->id, $data->username);

        if (empty($repo) || empty($repo['repo_path'])) {
            return 'repository-not-found';
        }

        $repo_path = rtrim($repo['repo_path'], '/');
        $realRepoPath = realpath($repo_path);

        // 🔒 Strong path validation
        $userHome = '/home/' . $data->username;

        if (
            $realRepoPath === false ||
            !is_dir($realRepoPath) ||
            !str_starts_with($realRepoPath, $userHome . '/')
        ) {
            return 'repository-not-found';
        }

        $gitDir = "/opt/cpguard/app/data/users/{$data->username}/applications";
        $gitJson = $gitDir . '/git.json';

        $entries = [];
        if (is_file($gitJson)) {
            $entries = json_decode(file_get_contents($gitJson), true) ?: [];
        }

        $entries = array_values(array_filter($entries, function ($entry) use ($data) {
            return ($entry['app_id'] ?? null) !== $data->id;
        }));

        // 🔐 Atomic write
        file_put_contents(
            $gitJson,
            json_encode($entries, JSON_PRETTY_PRINT),
            LOCK_EX
        );

        @chown($gitJson, $data->username);
        @chgrp($gitJson, $data->username);
        chmod($gitJson, 0644);

        $other_repos = array_filter($entries, function ($entry) use ($data, $repo_path) {
            return ($entry['app_id'] ?? null) !== $data->id && isset($entry['repo_path']) && $entry['repo_path'] === $repo_path;
        });

        if (!empty($other_repos)) {
            // There are other repositories using the same path, do not delete
            return true;
        }

        $gitHelper = new gitHelper($realRepoPath);
        $result = $gitHelper->delete($realRepoPath);

        return $result;
    }

    public static function getBranches($data)
    {
        $data = (array) $data;

        $repo_path = null;
        $clone_url = $data['clone_url'] ?? null;

        if (!$clone_url && !empty($data['id'])) {
            $repo = self::getJsonInfo($data['id'], $data['username']);
            $repo_path = $repo['repo_path'] ?? null;
        }

        $git = new gitHelper($repo_path);
        return $git->getBranches($clone_url, $data['ssh_key_path'] ?? null);
    }

    public static function getJsonInfo($id, $username)
    {
        $git_dir = "/opt/cpguard/app/data/users/" . $username . "/applications";
        $data_file = [];
        if (file_exists($git_dir . '/git.json')) {
            $data_file = json_decode(file_get_contents($git_dir . '/git.json'), true);
        }
        foreach ($data_file as $repo) {
            if ($repo['app_id'] === $id) {
                return $repo;
            }
        }

        return null;
    }

    public function webhook($data)
    {
        $repo = $this->getJsonInfo($data->id, $data->username);
        if (empty($repo)) {
            return 'repository-not-found';
        }
        $repo_path = rtrim($repo['repo_path'] ?? " ", '/');
        if (empty($repo_path) || !is_dir($repo_path)) {
            return 'repository-not-found';
        }

        $gitHelper = new gitHelper($repo_path);

        $pull = $gitHelper->pull($repo['branch'] ?? 'main');

        if ($data->deploy && $pull['status'] === true) {

            $deploy = $gitHelper->deploy();

            $head = $gitHelper->getCurrentCommitHash();

            if ($head !== false && isset($deploy['status']) && $deploy['status'] === true) {
                $git_dir = "/opt/cpguard/app/data/users/" . $data->username . "/applications";
                $git_json = $git_dir . '/git.json';
                $entries = [];
                if (is_file($git_json)) {
                    $entries = json_decode(file_get_contents($git_json), true);
                }
                foreach ($entries as &$entry) {
                    if ($entry['app_id'] === $data->id) {
                        $entry['deployed_head'] = $head;
                        $entry['last_deployed_at'] = date('Y-m-d H:i:s');
                        $entry['deploy_status'] = $deploy;
                        break;
                    }
                }

                file_put_contents($git_json, json_encode($entries, JSON_PRETTY_PRINT), LOCK_EX);
                @chown($git_json, $data->username);
                @chgrp($git_json, $data->username);
                chmod($git_json, 0644);
            }
        }

        return [
            'pull' => $pull,
            'deploy' => $deploy ?? ''
        ];
    }

    public function webhookDeploy($data)
    {
        $app_id = $data->app_id;
        $username = $data->username;

        $repo = $this->getJsonInfo($app_id, $username);

        if (!$repo) {
            dlog("Repository not found for app_id: $app_id and username: $username");
            return;
        }
        $auto_deploy = $repo['deployment']['webhook_switch'] ?? false;

        if (!$auto_deploy) {
            dlog("Auto-deploy not enabled for app_id: $app_id and username: $username");
            return;
        }
        $git = new gitHelper($repo['repo_path']);
        $pull_status = $git->pull($repo['branch'] ?? 'main');

        if (!$pull_status['status']) {
            dlog("Git pull failed for app_id: $app_id and username: $username");
            return;
        }
        $deploy_status = $git->deploy($repo['repo_path']);
        if (!$deploy_status['status']) {
            dlog("Deployment script failed for app_id: $app_id and username: $username");
            return;
        }

        $copy_files = $repo['deployment']['copy_files'] ?? [];

        if (($repo['deployment']['files_copy_switch'] ?? false) && !empty($copy_files['from']) && !empty($copy_files['to'])) {
            $git->copyFiles($copy_files, $username);
        }

        $head = $git->getCurrentCommitHash();
        if ($head !== false && isset($deploy_status['status'])) {
            $git_dir = "/opt/cpguard/app/data/users/" . $username . "/applications";
            $git_json = $git_dir . '/git.json';
            $entries = [];
            if (is_file($git_json)) {
                $entries = json_decode(file_get_contents($git_json), true);
            }
            foreach ($entries as &$entry) {
                if ($entry['app_id'] === $app_id) {
                    $entry['deployed_head'] = $head;
                    $entry['last_deployed_at'] = date('Y-m-d H:i:s');
                    $entry['deploy_status'] = $deploy_status;
                    break;
                }
            }

            file_put_contents($git_json, json_encode($entries, JSON_PRETTY_PRINT), LOCK_EX);
            @chown($git_json, $username);
            @chgrp($git_json, $username);
        }

        dlog("Auto-deploy completed for app_id: $app_id and username: $username");
        return;
    }

    public function testConnection($data)
    {
        $data = (array) $data;
        $gitHelper = new gitHelper(null);
        return $gitHelper->testConnection(
            $data['clone_url'],
            $data['ssh_key_path'] ?? null
        );
    }
}