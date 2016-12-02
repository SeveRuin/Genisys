<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\level\format\anvil;

use pocketmine\level\format\Chunk;
use pocketmine\level\format\LevelProvider;
use pocketmine\level\format\generic\GenericChunk;
use pocketmine\level\format\generic\SubChunk;
use pocketmine\level\format\mcregion\McRegion;
use pocketmine\level\Level;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\{ByteArrayTag, ByteTag, CompoundTag, IntArrayTag, IntTag, ListTag, LongTag};
use pocketmine\Player;
use pocketmine\tile\Spawnable;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\ChunkException;


class Anvil extends McRegion{

	public static function nbtSerialize(GenericChunk $chunk) : string{
		$nbt = new CompoundTag("Level", []);
		$nbt->xPos = new IntTag("xPos", $chunk->getX());
		$nbt->zPos = new IntTag("zPos", $chunk->getZ());

		$nbt->V = new ByteTag("V", 1);
		$nbt->LastUpdate = new LongTag("LastUpdate", 0); //TODO
		$nbt->InhabitedTime = new LongTag("InhabitedTime", 0); //TODO
		$nbt->TerrainPopulated = new ByteTag("TerrainPopulated", $chunk->isPopulated());
		$nbt->LightPopulated = new ByteTag("LightPopulated", $chunk->isLightPopulated());

		$nbt->Sections = new ListTag("Sections", []);
		$nbt->Sections->setTagType(NBT::TAG_Compound);
		$subChunks = -1;
		foreach($chunk->getSubChunks() as $subChunk){
			if($subChunk->isEmpty()){
				continue;
			}
			$nbt->Sections[++$subChunks] = new CompoundTag(null, [
				"Y"          => new ByteTag("Y", $subChunk->getY()),
				"Blocks"     => new ByteArrayTag("Blocks",     GenericChunk::reorderByteArray($subChunk->getBlockIdArray())), //Generic in-memory chunks are currrently always XZY
				"Data"       => new ByteArrayTag("Data",       GenericChunk::reorderNibbleArray($subChunk->getBlockDataArray())),
				"BlockLight" => new ByteArrayTag("BlockLight", GenericChunk::reorderNibbleArray($subChunk->getBlockLightArray())),
				"SkyLight"   => new ByteArrayTag("SkyLight",   GenericChunk::reorderNibbleArray($subChunk->getSkyLightArray()))
			]);
		}

		//$nbt->BiomeColors = new IntArrayTag("BiomeColors", $chunk->getBiomeColorArray());
		$nbt->HeightMap = new IntArrayTag("HeightMap", $chunk->getHeightMapArray());

		$entities = [];

		foreach($chunk->getEntities() as $entity){
			if(!($entity instanceof Player) and !$entity->closed){
				$entity->saveNBT();
				$entities[] = $entity->namedtag;
			}
		}

		$nbt->Entities = new ListTag("Entities", $entities);
		$nbt->Entities->setTagType(NBT::TAG_Compound);

		$tiles = [];
		foreach($chunk->getTiles() as $tile){
			$tile->saveNBT();
			$tiles[] = $tile->namedtag;
		}

		$nbt->TileEntities = new ListTag("TileEntities", $tiles);
		$nbt->TileEntities->setTagType(NBT::TAG_Compound);

		//TODO: TileTicks

		$writer = new NBT(NBT::BIG_ENDIAN);
		$nbt->setName("Level");
		$writer->setData(new CompoundTag("", ["Level" => $nbt]));

		return $writer->writeCompressed(ZLIB_ENCODING_DEFLATE, RegionLoader::$COMPRESSION_LEVEL);
	}

	public static function nbtDeserialize(string $data, LevelProvider $provider = null){
		$nbt = new NBT(NBT::BIG_ENDIAN);
		try{
			$nbt->readCompressed($data, ZLIB_ENCODING_DEFLATE);

			$chunk = $nbt->getData();

			if(!isset($chunk->Level) or !($chunk->Level instanceof CompoundTag)){
				throw new ChunkException("Invalid NBT format");
			}

			$chunk = $chunk->Level;

			$subChunks = [];
			if($chunk->Sections instanceof ListTag){
				foreach($chunk->Sections as $subChunk){
					if($subChunk instanceof CompoundTag){
						$subChunks[] = new SubChunk(
							$subChunk->Y->getValue(),
							GenericChunk::reorderByteArray($subChunk->Blocks->getValue()),
							GenericChunk::reorderNibbleArray($subChunk->Data->getValue()),
							GenericChunk::reorderNibbleArray($subChunk->BlockLight->getValue()),
							GenericChunk::reorderNibbleArray($subChunk->SkyLight->getValue())
						);
					}
				}
			}

			$result = new GenericChunk(
				$provider,
				$chunk["xPos"],
				$chunk["zPos"],
				$subChunks,
				$chunk->Entities instanceof ListTag ? $chunk->Entities->getValue() : [],
				$chunk->TileEntities instanceof ListTag ? $chunk->TileEntities->getValue() : [],
				/*$chunk->BiomeColors instanceof IntArrayTag ? $chunk->BiomeColors->getValue() : */ [], //TODO: remove this and revert to the original PC Biomes array
				$chunk->HeightMap instanceof IntArrayTag ? $chunk->HeightMap->getValue() : []
			);
			$result->setLightPopulated($chunk->LightPopulated instanceof ByteTag ? ((bool) $chunk->LightPopulated->getValue()) : false);
			$result->setPopulated($chunk->TerrainPopulated instanceof ByteTag ? ((bool) $chunk->TerrainPopulated->getValue()) : false);
			$result->setGenerated(true);
			return $result;
		}catch(\Throwable $e){
			echo $e->getMessage();
			return null;
		}
	}

	/** @var RegionLoader[] */
	protected $regions = [];

	/** @var AnvilChunk[] */
	protected $chunks = [];

	public static function getProviderName(){
		return "anvil";
	}

	public static function getProviderOrder(){
		return self::ORDER_YZX;
	}

	public static function isValid($path){
		$isValid = (file_exists($path . "/level.dat") and is_dir($path . "/region/"));

		if($isValid){
			$files = glob($path . "/region/*.mc*");
			foreach($files as $f){
				if(strpos($f, ".mcr") !== false){ //McRegion
					$isValid = false;
					break;
				}
			}
		}

		return $isValid;
	}

	public function requestChunkTask($x, $z) {
		$chunk = $this->getChunk($x, $z, false);
		if(!($chunk instanceof Chunk)) {
			throw new ChunkException("Invalid Chunk sent");
		}
		if($this->getServer()->asyncChunkRequest) {
			$task = new ChunkRequestTask($this->getLevel(), $chunk);
			$this->getServer()->getScheduler()->scheduleAsyncTask($task);
		} else {
			$tiles = "";
			if(count($chunk->getTiles()) > 0) {
				$nbt = new NBT(NBT::LITTLE_ENDIAN);
				$list = [];
				foreach($chunk->getTiles() as $tile) {
					if($tile instanceof Spawnable) {
						$list[] = $tile->getSpawnCompound();
					}
				}
				$nbt->setData($list);
				$tiles = $nbt->write(true);
			}
			$extraData = new BinaryStream();
			$extraData->putLInt(count($chunk->getBlockExtraDataArray()));
			foreach($chunk->getBlockExtraDataArray() as $key => $value) {
				$extraData->putLInt($key);
				$extraData->putLShort($value);
			}
			$ordered = $chunk->getBlockIdArray() .
				$chunk->getBlockDataArray() .
				$chunk->getBlockSkyLightArray() .
				$chunk->getBlockLightArray() .
				pack("C*", ...$chunk->getHeightMapArray()) .
				pack("N*", ...$chunk->getBiomeColorArray()) .
				$extraData->getBuffer() .
				$tiles;
			$this->getLevel()->chunkRequestCallback($x, $z, $ordered);
		}
		return null;
	}

	public function getWorldHeight() : int{
		//TODO: add world height options
		return 256;
	}

	/**
	 * @param $x
	 * @param $z
	 *
	 * @return RegionLoader
	 */
	protected function getRegion($x, $z){
		return $this->regions[Level::chunkHash($x, $z)] ?? null;
	}

	protected function loadRegion($x, $z){
		if(isset($this->regions[$index = Level::chunkHash($x, $z)])){
			return true;
		}

		$this->regions[$index] = new RegionLoader($this, $x, $z);

		return true;
	}
}