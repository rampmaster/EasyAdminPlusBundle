<?php

namespace Wandi\EasyAdminPlusBundle\Generator\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Console\Command\Command;
use Wandi\EasyAdminPlusBundle\Generator\EATool;
use Wandi\EasyAdminPlusBundle\Generator\Entity;
use Wandi\EasyAdminPlusBundle\Generator\Exception\EAException;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;
use Wandi\EasyAdminPlusBundle\Generator\GeneratorConfigInterface;

class GeneratorEntity  extends GeneratorBase implements GeneratorConfigInterface
{
    private $consoleOutput;

    public function buildServiceConfig()
    {
        $this->consoleOutput = new ConsoleOutput();
    }

    /**
     * TODO: Factoriser les fonctions generateFileEntity avec Eatool class
     * @param array $entitiesMetaData
     * @param Command $command
     * @throws EAException
     */
    public function run(array $entitiesMetaData, Command $command): void
    {
        $bundles = $this->container->getParameter('kernel.bundles');
        $relatedEntities = $this->getRelatedEntitiesMetaData($entitiesMetaData, $command, $bundles);
        $relatedEntities = array_merge($relatedEntities, $entitiesMetaData);

        $eaTool = new EATool($this->parameters);
        $eaTool->setParameterBag($this->container->getParameterBag()->all());
        $eaTool->initTranslation($this->parameters['translation_domain'], $this->projectDir);

        foreach ($relatedEntities as $entityMetaData)
        {
            $entity = new Entity($entityMetaData);
            $entity->setName(Entity::buildName(Entity::buildNameData($entityMetaData, $bundles)));
            $entity->setClass($entityMetaData->getName());
            $entity->buildMethods($this->parameters);
            $eaTool->addEntity($entity);
        }

        $eaTool->generateEntityFiles($this->projectDir, $this->consoleOutput);
        $this->updateMenuFile($eaTool->getEntities());
        $this->updateImportsFile($eaTool->getEntities());
    }

    /**
     * @param ArrayCollection $entities
     * @throws EAException
     */
    private function updateMenuFile(ArrayCollection $entities): void
    {
        $fileMenuContent = Yaml::parse(file_get_contents(sprintf( '%s/config/packages/wandi_easy_admin_plus/%s_menu.yaml', $this->projectDir, $this->parameters['pattern_file'])));

        if (!isset($fileMenuContent['easy_admin']['design']['menu']))
        {
            throw new EAException('no easy admin menu detected');
        }

        foreach ($entities as $entity)
        {
            //Si le l'entité n'existe pas dans le menu
            if (false === array_search($entity->getName(), array_column($fileMenuContent['easy_admin']['design']['menu'], 'entity')))
            {
                $fileMenuContent['easy_admin']['design']['menu'][] = EATool::buildEntryMenu($entity->getName());
            }
        }

        $ymlContent = EATool::buildDumpPhpToYml($fileMenuContent, $this->parameters);
        file_put_contents($this->projectDir . '/config/packages/wandi_easy_admin_plus/' . $this->parameters['pattern_file'] . '_menu.yaml', $ymlContent);
    }

    /**
     * @param ArrayCollection $entities
     * @throws EAException
     */
    private function updateImportsFile(ArrayCollection $entities): void
    {
        $fileMenuContent = Yaml::parse(file_get_contents(sprintf( '%s/config/packages/easy_admin.yaml', $this->projectDir)));

        if (!isset($fileMenuContent['imports']))
        {
            throw new EAException('There is no imports option in the configuration file.');
        }

        foreach ($entities as $entity)
        {
            $patternEntity = 'wandi_easy_admin_plus/' . $this->parameters['pattern_file'] . '_' . $entity->getName() . '.yaml';

            //Si le l'entité n'existe pas dans les fichiers
            if (false === array_search($patternEntity, array_column($fileMenuContent['imports'], 'resource')))
            {
                $fileMenuContent['imports'][] = [
                    'resource' => $patternEntity,
                ];
            }
        }

        $ymlContent = EATool::buildDumpPhpToYml($fileMenuContent, $this->parameters);
        if (!file_put_contents(sprintf( '%s/config/packages/easy_admin.yaml', $this->projectDir), $ymlContent))
            throw new EAException(sprintf('Can not update imported files in %s/config/packages/easy_admin.yaml', $this->projectDir));
    }

    private function getRelatedEntitiesMetaData(array $entitiesMetaData, Command $command, array $bundles): array
    {
        $listMetaData = $this->em->getMetadataFactory()->getAllMetadata();
        $relatedEntities = [
            'name' => [],
            'metaData' => [],
        ];
        $consoleInput = new ArgvInput();
        $consoleOutput = new ConsoleOutput();
        $helper = $command->getHelper('question');

        $entitiesName = array_map(function($entityMetaData) {
            return $entityMetaData->getName();
        }, $entitiesMetaData);

        foreach ($listMetaData as $metaData)
        {
            if (empty($metaData->associationMappings))
                continue ;

            foreach ($metaData->associationMappings as $associationMapping)
            {
                if (in_array($associationMapping['targetEntity'], $entitiesName))
                {
                    //Si déjà présent dans les entités liées, on next
                    if (in_array($associationMapping['targetEntity'], $relatedEntities['name']))
                        continue ;

                    $question = new ConfirmationQuestion(sprintf('L\'entité <info>%s</info> est lié, voulez-vous (re)générer son fichier de configuration [<info>y</info>/n]?', $metaData->name), true);
                    if ($helper->ask($consoleInput, $consoleOutput, $question))
                    {
                        $relatedEntities['name'][] = $metaData->getName();
                        $relatedEntities['metaData'][] = $metaData;
                    }
                }
            }
        }
        return $relatedEntities['metaData'];
    }
}