<?php
// 参考 https://saitodev.co/article/PHP%E3%81%A7%E7%B0%A1%E6%98%93%E7%9A%84%E3%81%AA%E3%82%AF%E3%83%AD%E3%83%BC%E3%83%A9%E3%82%92%E4%BD%9C%E3%81%A3%E3%81%A6%E3%81%BF%E3%81%9F

class Archive{

	// http:から始まるURL 末尾に/を付けない #を付けない ?は区別する
	// マルチバイト文字可（パーセントエンコードされていない状態）
	static private $urlList = array(0 => ["dummyUrl", "dummyFileName", "dummyFileType"]);
	static private $regex = '{(?<==")((https?:)?\/\/)?([^"/\s]+?)\.([a-z\.]{2,6})([^"\s]*)(?=")}';
  //                               $1 $2            $3           $4            $5
  // 相対パスとか全然考えてなかったしこれ絶対src="とかhref="の後ってしたほうが良かった

	static private $crawlDepth = 2;

	// Wikiにあるページ数より多い数を入れる
	static private $pageAmount = 200;

	// 保存しないページのURL
	static private $blackList_regex = ""  // アップロード用に削除

	static function execute(){
		mkdir("./data_raw");
		mkdir("./data_modified");

		// wikiの全ページを配列に追加
		for($i=1; $i<self::$pageAmount; $i++){
			self::$urlList[] = [""/* URL，アップロード用に削除 */.$i.'.html',
			                    $i.".html",
												  "html"];
		}
		self::crawl();
	}

	static private function crawl(){

		$hierarchy = 0;
		$urlNum = 1;
		$checkPoint = count(self::$urlList);
		while(count(self::$urlList) > $urlNum){
			// 階層を数える
			if($urlNum >= $checkPoint){
				$hierarchy++;
				if($hierarchy > self::$crawlDepth) break;
				echo "\n---------- Hierarchy ".$hierarchy." ----------\n\n";
				$checkPoint = count(self::$urlList);
			}

      // 探索するurl，ファイル名，ファイルの種類を取り出す
      list($url, $fileName, $fileType) = self::$urlList[$urlNum];

      // マルチバイト文字を含むURLをパーセントエンコード
      $url_encoded = parseTagsRecursive($url);

      // 連続アクセスで規制されないように5秒待ってファイルにアクセス
      sleep(5);
			$file = file_get_contents($url_encoded);

		  // ファイルを取得できなければ次へ
		  if(empty($file)){
				echo "file ".$urlNum." not found. ".$url;
				$urlNum++;
				continue;
			}

			// 0階層目（Wikiのページ）のみ生データを保存
			if($hierarchy == 0){
				file_put_contents(sprintf('./data_raw/%04d_%s', $urlNum, $fileName), $file);
			}

			// htmlファイルであれば，中を見て処理
			if(preg_match("/^html?$/i", $fileType) == 1){
				// 最終階層以外では，htmlはリンクを修正する

				// HTMLに含まれるリンクを取得
				preg_match_all(self::$regex, $file, $matches, PREG_SET_ORDER);

        // HTMLに含まれていた各リンクについての処理
				foreach ($matches as $key => $match) {
					// 取得したURLを保存する形に整形
					// httpsをhttpに，末尾に/を付けない，#以降は消す，?は付けたまま
					$link = $match[0];
					if($match[1] == "") $link = "http://".$link;
					elseif($match[2] == "") $link = "http:".$link;
					$link = preg_replace("{^https}", "http", $link, 1);
					$link = preg_replace("{/$}", "", $link, 1);
					$link = explode("#", $link)[0];
					$link = urldecode($link);  // もともと%が入ったURLはバグりそうだけどそんなの入れるほうが悪い(# ﾟДﾟ)

					// リンクがまだリストに登録されていないか
					$i = array_search($link, array_column(self::$urlList, 0));
					if($i === false){

  					// リンク先のファイル名とファイルの種類を取得
	  				list($link_fileName, $link_fileType) = self::urlToFileName($link);
		  			if(empty($link_fileName) or $link_fileName === False) continue;

						// 最終階層でなく，
					  // リンクがブラックリストに入っていなければ
					  // 取得したURLをリストに追加
					  if($hierarchy != self::$crawlDepth
				       and preg_match(self::$blackList_regex, $link) == 0)
					  {
					  	self::$urlList[] = [$link, $link_fileName, $link_fileType];
					  	$i = count(self::$urlList) - 1;
					  }
				  }

					//echo $match[0]."\n";  // デバッグ用

          // 新しいリンクだが最終階層orブラックリストのためHTMLを書き換えない場合
					if($i === false) continue;

          // ファイル名取得
					$link_fileName = self::$urlList[$i][1];

					// HTMLに含まれるリンクの書き換え
					$origLink_unsharped = explode("#", $match[0]);
					$r = count($origLink_unsharped) == 1
					     ? '{(?<=")\Q'.$origLink_unsharped[0].'\E(?=")}'
						   : '{(?<=")\Q'.$origLink_unsharped[0].'\E(?=#'.$origLink_unsharped[1].'")}';
					$file = preg_replace($r,
					                     sprintf('./%06d_%s', $i, $link_fileName),
												       $file);

				}
				// Wikiに貼られた画像がなんかそのままだと読み込まれないので直す
				$file = str_replace('data-original="', 'src="', $file);

				// 元ページへのリンクをHTMLに追加
				$file = preg_replace("{(<\s*body.*?>)}",
									           '\1<a href='.$url.'>元ページへのリンクはこちら</a>',
									           $file);

			}

			// ファイルを保存
			file_put_contents(sprintf('./data_modified/%06d_%s', $urlNum, $fileName), $file);

			// ログを表示
			echo "Saved: ".$urlNum." files;  Found: ".count(self::$urlList)." links\n\n";

			$urlNum++;

	  }
		file_put_contents("./urlList.dat", serialize(self::$urlList));
		file_put_contents("./urlList.txt", self::$urlList);
		echo "succeeded.\n";
		return;
	}

  // URL(整形済み)からファイル名と拡張子を生成する
	static private function urlToFileName($url){
		$url_slashed = explode("/", $url);
		if(($c = count($url_slashed)) < 3) return array(false, false); // http://から始まっていれば3以上のはず
		$fileName = end($url_slashed);
		$fileName = explode("?", $fileName)[0];
		$fileName = explode("#", $fileName)[0];

		$fileName_dotted = explode(".", $fileName);
		$fileType = end($fileName_dotted);

    // リンク先のファイル名に拡張子がなかった場合，htmlと判断
		if(count($fileName_dotted) == 1){
			$fileName .= ".html";
			$fileType = "html";
		}

		// 謎の拡張子だったらHTMLじゃないか確認
		// .jpとか.comが混入しがちなのでその対策
		elseif(preg_match("/^(jpe?g|png|gif|bmp|ico|svg|html?|js|php|css|json|xml|atom)$/i", $fileType) == 0){
			sleep(3);
			$file_temp = file_get_contents(parseTagsRecursive($url));
      if(preg_match("/^<!DOCTYPE html/i", $file_temp) == 1){
				$fileName .= ".html";
				$fileType .= "html";
			}
		}

		return array($fileName, $fileType);
	}

}

// パーセントエンコードするやつ
// これをそのまま持ってきた https://tips.recatnap.info/laboratory/detail/id/165
function parseTagsRecursive($input) {
  $regex = '/[^\x01-\x7E]/u';
  if (is_array($input)) {
    $input = urlencode($input[0]);
  }
  return preg_replace_callback($regex, 'parseTagsRecursive', $input);
}

Archive::execute();

?>
