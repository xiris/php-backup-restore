<?php
/**
 * Created by PhpStorm.
 * User: dwendlandt
 * Date: 15/09/15
 * Time: 14:21
 */
namespace Elastification\BackupRestore\Repository;

use Elastification\BackupRestore\Entity\IndexTypeStats;
use Elastification\BackupRestore\Entity\Mappings;
use Elastification\BackupRestore\Entity\ServerInfo;
use Elastification\BackupRestore\Helper\VersionHelper;
use Elastification\BackupRestore\Repository\ElasticQuery\QueryInterface;
use Elastification\Client\Request\V1x\NodeInfoRequest;
use Elastification\Client\Request\V1x\SearchRequest;
use Elastification\Client\Response\V1x\NodeInfoResponse;

class ElasticsearchRepository extends AbstractElasticsearchRepository implements ElasticsearchRepositoryInterface
{

    /**
     * @var ServerInfo
     */
    private $serverInfo;

    /**
     * Gets the server info for the current host
     *
     * @param string $host
     * @param int $port
     * @return ServerInfo
     * @author Daniel Wendlandt
     */
    public function getServerInfo($host, $port = 9200)
    {
        $request = new NodeInfoRequest($this->getSerializer());
        $client = $this->getClient($host, $port);
        /** @var NodeInfoResponse $response */
        $response = $client->send($request);

        $serverInfo = new ServerInfo();
        $serverInfo->name = $response->getData()['name'];
        $serverInfo->clusterName = $response->getData()['cluster_name'];
        $serverInfo->version = $response->getData()['version']['number'];

        $this->serverInfo = $serverInfo;

        return $serverInfo;
    }

    /**
     * Checks for number documents in all indices/types
     *
     * @param string $host
     * @param int $port
     * @return IndexTypeStats
     * @throws \Exception
     * @author Daniel Wendlandt
     */
    public function getDocCountByIndexType($host, $port = 9200)
    {
        $this->checkServerInfo($host, $port);
        $queryClassName = $this->getQueryClass('DocsInIndexTypeQuery');

        /** @var QueryInterface $query */
        $query = new $queryClassName();

        $requestClassName = $this->getRequestClass('SearchRequest');

        /** @var SearchRequest $request */
        $request = new $requestClassName(null, null, $this->getSerializer());
        $request->setBody($query->getBody());

        $client = $this->getClient($host, $port);
        $response = $client->send($request);

        $indexTypeStats = new IndexTypeStats();
        foreach($response->getData()['aggregations']['count_docs_in_index']['buckets'] as $indexBucket) {
            $index = new IndexTypeStats\Index();
            $index->setName($indexBucket['key']);
            $index->setDocsInIndex($indexBucket['doc_count']);

            foreach($indexBucket['count_docs_in_types']['buckets'] as $typeBucket) {
                $type = new IndexTypeStats\Type();
                $type->setName($typeBucket['key']);
                $type->setDocsInType($typeBucket['doc_count']);

                $index->addType($type);
            }

            $indexTypeStats->addIndex($index);
        }

        return $indexTypeStats;
    }

    /**
     * Get mappings for all indices
     *
     * @param string $host
     * @param int $port
     * @return Mappings
     * @throws \Exception
     * @author Daniel Wendlandt
     */
    public function getAllMappings($host, $port = 9200)
    {
        $this->checkServerInfo($host, $port);

        $requestClassName = $this->getRequestClass('Index\\GetMappingRequest');
        $request = new $requestClassName(null, null, $this->getSerializer());

        $client = $this->getClient($host, $port);

        $response = $client->send($request);

        $mappings = new Mappings();
        foreach($response->getData() as $indexName => $typeMappings) {
            $index = new Mappings\Index();
            $index->setName($indexName);

            foreach($typeMappings['mappings'] as $typeName => $schema) {
                $type = new Mappings\Type();
                $type->setName($typeName);
                $type->setSchema($schema);

                $index->addType($type);
            }

            $mappings->addIndices($index);
        }

        return $mappings;
    }

    private function checkServerInfo($host, $port = 9200)
    {
        if(null === $this->serverInfo) {
            $this->getServerInfo($host, $port);
        }

        if(!VersionHelper::isVersionAllowed($this->serverInfo->version)) {
            throw new \Exception('Elasticsearch version ' . $this->serverInfo->version . ' is not supported by this tool');
        }
    }

    /**
     * Generates a fully qualified classname for queries of elastification
     *
     * @param string $className
     * @return string
     * @author Daniel Wendlandt
     */
    private function getQueryClass($className)
    {
        $namespace = 'Elastification\\BackupRestore\\Repository\\ElasticQuery\\V%sx\\%s';

        return $this->generateClassName($namespace, $className);
    }

    /**
     * Generates a fully qualified classname for requests of elastification
     *
     * @param string $className
     * @return string
     * @author Daniel Wendlandt
     */
    private function getRequestClass($className)
    {
        $namespace = 'Elastification\\Client\\Request\\V%sx\\%s';

        return $this->generateClassName($namespace, $className);
    }

    /**
     * Generates a class with correct version path and namespace
     *
     * @param string $namespace
     * @param string $className
     * @return string
     * @author Daniel Wendlandt
     */
    private function generateClassName($namespace, $className)
    {
        $version = explode('.', $this->serverInfo->version);

        return sprintf($namespace, $version[0], $className);
    }
}

