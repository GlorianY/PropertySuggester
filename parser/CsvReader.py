"""
read_csv returns a generator that yields the tuple (title, [claim1, claim2])

usage:
with open("file.csv", "r") as f:
    for title, claims in read_csv(f):
        do_things()

"""

import argparse, time
from CompressedFileType import CompressedFileType
from claim import Claim

def read_csv(input_file, seperator=","):
    """
    @rtype : collections.Iterable[(string, list[Claim])]
    @type input_file: file
    @type seperator: str
    """
    current_title = None
    claims = []
    for line in input_file:
        title, prop, datatype, value = line.strip().split(seperator, 3)
        if current_title != title:
            if not current_title is None:
                yield current_title, claims
            current_title = title
            claims = []
        claims.append(Claim(int(prop), datatype, value))

    if not current_title is None:
        yield current_title, claims


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("input", help="The CSV input file (a wikidata dump), gzip is supported",
                        type=CompressedFileType('r'))
    parser.add_argument("-s", "--silent", help="Show output", action="store_true")
    args = parser.parse_args()


    start = time.time()
    if args.silent:
        for element in read_csv(args.input):
            pass
    else:
        for element in read_csv(args.input):
            print element

    print "total time: %.2fs"%(time.time() - start)