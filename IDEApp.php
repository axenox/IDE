<?php
namespace axenox\IDE;

use exface\Core\Interfaces\InstallerInterface;
use exface\Core\CommonLogic\Model\App;
use exface\Core\Facades\AbstractHttpFacade\HttpFacadeInstaller;
use exface\Core\Factories\FacadeFactory;
use axenox\IDE\Facades\IDEFacade;

/**
 * 
 * @author Andrej Kabachnik
 *
 */
class IDEApp extends App
{
    /**
     * {@inheritdoc}
     * @see App::getInstaller($injected_installer)
     */
    public function getInstaller(InstallerInterface $installer = null)
    {        
        $container = parent::getInstaller($installer);
        // IDE facade
        $facadeInstaller = new HttpFacadeInstaller($this->getSelector());
        $facadeInstaller->setFacade(FacadeFactory::createFromString(IDEFacade::class, $this->getWorkbench()));
        $container->addInstaller($facadeInstaller);
        
        return $container;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\App::getUid()
     */
    public function getUid() : ?string
    {
        // Hardcode the UID of the core app, because some installers might attempt to use it
        // before the model is fully functional on first time installing.
        return '0x11ed96509743e0c29650025041000001';
    }
}