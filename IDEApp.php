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
        // IDE facade
        $facadeInstaller = new HttpFacadeInstaller($this->getSelector());
        $facadeInstaller->setFacade(FacadeFactory::createFromString(IDEFacade::class, $this->getWorkbench()));
        $installer->addInstaller($facadeInstaller);
        
        return $installer;
    }
}