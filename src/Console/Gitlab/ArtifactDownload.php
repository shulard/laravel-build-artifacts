<?php

namespace Shulard\BuildArtifacts\Console\Commands\Gitlab;

use Illuminate\Console\Command;

class Download extends Command
{
    /**
     * Gitlab API URL to project build collection
     */
    const PROJECT_BUILD_URL = "%s/api/v3/projects/%d/builds?per_page=%d";

    /**
     * Gitlab API URL to download artifact for a given build
     */
    const ARTIFACT_BUILD_URL = "%s/api/v3/projects/%d/builds/%d/artifacts";

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'artifact:gitlab:download '.
        '{--token=        : Gitlab authentication token.} '.
        '{--project=      : Project identifier on which artifact was built.} '.
        '{--in=.          : Path where the artifact must be extracted.} '.
        '{--ref=          : Repository ref the build was ran on.} '.
        '{--tag=          : Repository tag the build was ran on.} '.
        '{--stage=prepare : Build stage from which artifact is downloaded}'.
        '{--perpage=50    : Number of items to be retrieve from API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieve a Gitlab artifact and install it in the project';

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \RuntimeException
     */
    public function handle()
    {
        $url = env('GITLAB_API', 'https://gitlab.com');

        if (null === $project = $this->option('project')) {
            $project = env('GITLAB_PROJECT');
        }
        if (null === $token = $this->option('token')) {
            $token = env('GITLAB_TOKEN');
        }

        $in = realpath($this->option('in'));
        if (!is_dir($in) || !is_writable($in)) {
            throw new \RuntimeException('Invalid --in option, must be a valid path !');
        }
        if (strpos($in, base_path()) === false) {
            throw new \RuntimeException('--in option must define a folder inside current project !');
        }

        $build = $this->getLatestSuccessfulBuild(
            $url,
            $token,
            $project,
            $this->option('stage'),
            $this->option('ref'),
            $this->option('tag')
        );

        $path = base_path().'/storage/artifact-'.$build['id'].'.zip';
        try {
            $zip = fopen($path, 'w+');
            $this->api(
                sprintf(self::ARTIFACT_BUILD_URL, $url, $project, $build['id']),
                $token,
                $zip
            );
            fclose($zip);
        } catch (\Exception $error) {
            $this->error('Can\'t download artifact to '.$path);
            fclose($zip);
        }

        $this->installArtifact($path, $in);
        unlink($path);
    }

    /**
     * Retrieve latest successful build information
     * @param  string $url      Gitlab API root URL
     * @param  string $token    Gitlab API Token
     * @param  integer $project Project identifier
     * @return array
     * @throws \RuntimeException
     */
    private function getLatestSuccessfulBuild($url, $token, $project, $stage, $ref, $tag)
    {
        $result = $this->apiJson(
            sprintf(self::PROJECT_BUILD_URL, $url, $project, $this->option('perpage')),
            $token
        );

        $result = collect($result)->reject(function ($item) use ($stage, $ref, $tag) {
            return $item['status'] !== 'success'
                || $item['stage'] !== $stage
                || (null !== $ref && $item['ref'] !== $ref)
                || (null !== $tag && $item['tag'] !== $tag);
        })->first();
        if (null === $result) {
            throw new \RuntimeException('Can\'t find a successful build in the project...');
        }

        $this->info(sprintf(
            "Latest build [%s]\n- ref: %s\n- stage: %s\n- at: %s\n- runner: %d -> %s\n- triggered by: %s",
            $result['id'],
            $result['ref'],
            $result['stage'],
            $result['created_at'],
            $result['runner']['id'],
            $result['runner']['description'],
            $result['user']['username']
        ));

        return $result;
    }

    /**
     * Build a curl handle capable to make Gitlab API request
     * @param  string $url
     * @param  string $token Gitlab API Tokan
     * @return resource
     * @throws \RuntimeException
     */
    private function api($url, $token, $file = null)
    {
        $h = curl_init();
        $this->comment($url);
        if (null === $file) {
            curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
        } else {
            curl_setopt($h, CURLOPT_FILE, $file);
        }
        curl_setopt($h, CURLOPT_URL, $url);
        curl_setopt($h, CURLOPT_HTTPHEADER, [
            sprintf("PRIVATE-TOKEN: %s", $token)
        ]);

        $raw = curl_exec($h);
        if (curl_getinfo($h, CURLINFO_HTTP_CODE) - 200 > 100) {
            throw new \RuntimeException(sprintf(
                "Error during API call\n[%d] -> %s",
                curl_getinfo($h, CURLINFO_HTTP_CODE),
                $raw
            ));
        }
        curl_close($h);

        return $raw;
    }

    /**
     * Make an API call to retrieve JSON formatted results
     * @param  string $url
     * @param  string $token
     * @return array
     * @throws \RuntimeException
     */
    private function apiJson($url, $token)
    {
        $raw = $this->api($url, $token);

        if (null === $result = json_decode($raw, true)) {
            throw new \RuntimeException("Can't decode Gitlab API response...");
        }
        return $result;
    }

    /**
     * Install artifact in the specified folder
     * @param  string $path
     * @param  string $in
     * @throws \RuntimeException
     */
    private function installArtifact($path, $in)
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) === true) {
            $zip->extractTo($in);
            $zip->close();

            $this->info('Artifact extracted in : '.$in);
        } else {
            throw new \RuntimeException(sprintf(
                'Can\'t extract ZIP file "%s" in "%s"',
                basename($path),
                $in
            ));
        }
    }
}
