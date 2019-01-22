<?php
namespace Neos\RedirectHandler\NeosAdapter\Service;

/*
 * This file is part of the Neos.RedirectHandler.NeosAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Request;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;

/**
 * Service that creates redirects for moved / deleted nodes.
 *
 * Note: This is usually invoked by signals.
 *
 * @Flow\Scope("singleton")
 */
class NodeRedirectService
{
    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @Flow\Inject
     * @var RouterCachingService
     */
    protected $routerCachingService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="Neos.RedirectHandler")
     * @var array
     */
    protected $defaultStatusCode;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @Flow\InjectConfiguration(path="enableRemovedNodeRedirect", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $enableRemovedNodeRedirect;

    /**
     * @Flow\InjectConfiguration(path="restrictByPathPrefix", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByPathPrefix;

    /**
     * @Flow\InjectConfiguration(path="restrictByNodeType", package="Neos.RedirectHandler.NeosAdapter")
     * @var array
     */
    protected $restrictByNodeType;

    /**
     * @var array
     */
    protected $pendingRedirects = [];

    /**
     * Collects the node for redirection if it is a 'Neos.Neos:Document' node and its URI has changed
     *
     * @param NodeInterface $node The node that is about to be published
     * @param Workspace $targetWorkspace
     * @return void
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function collectPossibleRedirects(NodeInterface $node, Workspace $targetWorkspace)
    {
        $nodeType = $node->getNodeType();
        if ($targetWorkspace->isPublicWorkspace() === false || $nodeType->isOfType('Neos.Neos:Document') === false) {
            return;
        }
        $this->appendNodeAndChildrenDocumentsToPendingRedirects($node, $targetWorkspace);
    }

    /**
     * Creates the queued redirects provided we can find the node.
     *
     * @return void
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function createPendingRedirects()
    {
        $this->nodeFactory->reset();
        foreach ($this->pendingRedirects as $nodeIdentifierAndWorkspace => $oldUriPerDimensionCombination) {
            list($nodeIdentifier, $workspaceName) = explode('@', $nodeIdentifierAndWorkspace);
            $this->buildRedirects($nodeIdentifier, $workspaceName, $oldUriPerDimensionCombination);
        }

        $this->persistenceManager->persistAll();
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return void
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function appendNodeAndChildrenDocumentsToPendingRedirects(NodeInterface $node, Workspace $targetWorkspace)
    {
        $identifierAndWorkspaceKey = $node->getIdentifier() . '@' . $targetWorkspace->getName();
        if (isset($this->pendingRedirects[$identifierAndWorkspaceKey])) {
            return;
        }

        if (!$this->hasNodeUriChanged($node, $targetWorkspace)) {
            return;
        }

        $this->pendingRedirects[$identifierAndWorkspaceKey] = $this->createUriPathsAcrossDimensionsForNode($node->getIdentifier(), $targetWorkspace);

        foreach ($node->getChildNodes('Neos.Neos:Document') as $childNode) {
            $this->appendNodeAndChildrenDocumentsToPendingRedirects($childNode, $targetWorkspace);
        }
    }

    /**
     * @param string $nodeIdentifier
     * @param Workspace $targetWorkspace
     * @return array
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function createUriPathsAcrossDimensionsForNode(string $nodeIdentifier, Workspace $targetWorkspace): array
    {
        $result = [];
        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $allowedCombination) {
            $nodeInDimensions = $this->getNodeInWorkspaceAndDimensions($nodeIdentifier, $targetWorkspace->getName(), $allowedCombination);
            if ($nodeInDimensions === null) {
                continue;
            }

            $nodeUriPath = $this->buildUriPathForNode($nodeInDimensions);
            $nodeUriPath = $this->removeContextInformationFromRelativeNodeUri($nodeUriPath);
            $result[] = [
                $nodeUriPath,
                $allowedCombination
            ];
        }

        return $result;
    }

    /**
     * Has the Uri changed at all.
     *
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return bool
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function hasNodeUriChanged(NodeInterface $node, Workspace $targetWorkspace): bool
    {
        $newUriPath = $this->buildUriPathForNode($node);
        $newUriPath = $this->removeContextInformationFromRelativeNodeUri($newUriPath);

        $nodeInTargetWorkspace = $this->getNodeInWorkspace($node, $targetWorkspace);
        if (!$nodeInTargetWorkspace) {
            return false;
        }
        $oldUriPath = $this->buildUriPathForNode($nodeInTargetWorkspace);
        $oldUriPath = $this->removeContextInformationFromRelativeNodeUri($oldUriPath);

        return ($newUriPath !== $oldUriPath);
    }

    /**
     * Build redirects in all dimensions for a given node.
     *
     * @param string $nodeIdentifier
     * @param string $workspaceName
     * @param $oldUriPerDimensionCombination
     * @return void
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function buildRedirects(string $nodeIdentifier, string $workspaceName, array $oldUriPerDimensionCombination)
    {
        foreach ($oldUriPerDimensionCombination as list($oldRelativeUri, $dimensionCombination)) {
            $this->createRedirectFrom($oldRelativeUri, $nodeIdentifier, $workspaceName, $dimensionCombination);
        }
    }

    /**
     * Gets the node in the given dimensions and workspace and redirects the oldUri to the new one.
     *
     * @param string $oldUri
     * @param string $nodeIdentifer
     * @param string $workspaceName
     * @param array $dimensionCombination
     * @return bool
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function createRedirectFrom(string $oldUri, string $nodeIdentifer, string $workspaceName, array $dimensionCombination): bool
    {
        $node = $this->getNodeInWorkspaceAndDimensions($nodeIdentifer, $workspaceName, $dimensionCombination);
        if ($node === null) {
            return false;
        }

        if ($this->isRestrictedByNodeType($node) || $this->isRestrictedByPath($node)) {
            return false;
        }

        $newUri = $this->buildUriPathForNode($node);

        if ($node->isRemoved()) {
            return $this->removeNodeRedirectIfNeeded($node, $newUri);
        }

        if ($oldUri === $newUri) {
            return false;
        }

        $hosts = $this->getHostnames($node->getContext());
        $this->flushRoutingCacheForNode($node);
        $statusCode = (integer)$this->defaultStatusCode['redirect'];

        $this->redirectStorage->addRedirect($oldUri, $newUri, $statusCode, $hosts);

        return true;
    }

    /**
     * Removes a redirect
     *
     * @param NodeInterface $node
     * @param string $newUri
     * @return bool
     */
    protected function removeNodeRedirectIfNeeded(NodeInterface $node, string $newUri): bool
    {
        // By default the redirect handling for removed nodes is activated.
        // If it is deactivated in your settings you will be able to handle the redirects on your own.
        // For example redirect to dedicated landing pages for deleted campaign NodeTypes
        if ($this->enableRemovedNodeRedirect) {
            $hosts = $this->getHostnames($node->getContext());
            $this->flushRoutingCacheForNode($node);
            $statusCode = (integer)$this->defaultStatusCode['gone'];
            $this->redirectStorage->addRedirect($newUri, '', $statusCode, $hosts);

            return true;
        }

        return false;
    }

    /**
     * Removes any context information appended to a node Uri.
     *
     * @param string $relativeNodeUri
     * @return string
     */
    protected function removeContextInformationFromRelativeNodeUri(string $relativeNodeUri): string
    {
        // FIXME: Uses the same regexp than the ContentContextBar Ember View, but we can probably find something better.
        return (string)preg_replace('/@[A-Za-z0-9;&,\-_=]+/', '', $relativeNodeUri);
    }

    /**
     * Check if the current node type is restricted by Settings
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isRestrictedByNodeType(NodeInterface $node): bool
    {
        if (!isset($this->restrictByNodeType)) {
            return false;
        }

        foreach ($this->restrictByNodeType as $disabledNodeType => $status) {
            if ($status !== true) {
                continue;
            }
            if ($node->getNodeType()->isOfType($disabledNodeType)) {
                $this->systemLogger->log(vsprintf('Redirect skipped based on the current node type (%s) for node %s because is of type %s', [
                    $node->getNodeType()->getName(),
                    $node->getContextPath(),
                    $disabledNodeType
                ]), LOG_DEBUG, null, 'RedirectHandler');

                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current node path is restricted by Settings
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isRestrictedByPath(NodeInterface $node): bool
    {
        if (!isset($this->restrictByPathPrefix)) {
            return false;
        }

        foreach ($this->restrictByPathPrefix as $pathPrefix => $status) {
            if ($status !== true) {
                continue;
            }
            $pathPrefix = rtrim($pathPrefix, '/') . '/';
            if (mb_strpos($node->getPath(), $pathPrefix) === 0) {
                $this->systemLogger->log(vsprintf('Redirect skipped based on the current node path (%s) for node %s because prefix matches %s', [
                    $node->getPath(),
                    $node->getContextPath(),
                    $pathPrefix
                ]), LOG_DEBUG, null, 'RedirectHandler');

                return true;
            }
        }

        return false;
    }

    /**
     * Collects all hostnames from the Domain entries attached to the current site.
     *
     * @param ContentContext $contentContext
     * @return array
     */
    protected function getHostnames(ContentContext $contentContext): array
    {
        $domains = [];
        $site = $contentContext->getCurrentSite();
        if ($site === null) {
            return $domains;
        }

        foreach ($site->getActiveDomains() as $domain) {
            /** @var Domain $domain */
            $domains[] = $domain->getHostname();
        }

        return $domains;
    }

    /**
     * Removes all routing cache entries for the given $nodeData
     *
     * @param NodeInterface $node
     * @return void
     */
    protected function flushRoutingCacheForNode(NodeInterface $node)
    {
        $nodeData = $node->getNodeData();
        $nodeDataIdentifier = $this->persistenceManager->getIdentifierByObject($nodeData);
        if ($nodeDataIdentifier === null) {
            return;
        }
        $this->routerCachingService->flushCachesByTag($nodeDataIdentifier);
    }

    /**
     * Creates a (relative) URI for the given $nodeContextPath removing the "@workspace-name" from the result
     *
     * @param NodeInterface $node
     * @return string the resulting (relative) URI
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    protected function buildUriPathForNode(NodeInterface $node): string
    {
        return $this->getUriBuilder()
            ->uriFor('show', ['node' => $node], 'Frontend\\Node', 'Neos.Neos');
    }

    /**
     * Creates an UriBuilder instance for the current request
     *
     * @return UriBuilder
     */
    protected function getUriBuilder(): UriBuilder
    {
        if ($this->uriBuilder !== null) {
            return $this->uriBuilder;
        }

        $httpRequest = Request::createFromEnvironment();
        $actionRequest = new ActionRequest($httpRequest);
        $this->uriBuilder = new UriBuilder();
        $this->uriBuilder
            ->setRequest($actionRequest);
        $this->uriBuilder
            ->setFormat('html')
            ->setCreateAbsoluteUri(false);

        return $this->uriBuilder;
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $targetWorkspace
     * @return NodeInterface|null
     */
    protected function getNodeInWorkspace(NodeInterface $node, Workspace $targetWorkspace)
    {
        return $this->getNodeInWorkspaceAndDimensions($node->getIdentifier(), $targetWorkspace->getName(), $node->getContext()->getDimensions());
    }

    /**
     * @param string $nodeIdentifier
     * @param string $workspaceName
     * @param array $dimensionCombination
     * @return NodeInterface|null
     */
    protected function getNodeInWorkspaceAndDimensions(string $nodeIdentifier, string $workspaceName, array $dimensionCombination)
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensionCombination,
            'invisibleContentShown' => true,
        ]);

        return $context->getNodeByIdentifier($nodeIdentifier);
    }
}
